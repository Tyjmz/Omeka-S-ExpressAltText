<?php
namespace ExpressAltText\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Validator\Csrf;
use Laminas\View\Model\ViewModel;
use Omeka\Entity\Media;

class AdminController extends AbstractActionController
{
    private $api;
    private $em;
    private $acl;
    private $auth;
    private $settings;

    public function __construct($api, $em, $acl, $auth, $settings)
    {
        $this->api = $api;
        $this->em = $em;
        $this->acl = $acl;
        $this->auth = $auth;
        $this->settings = $settings;
    }

    public function indexAction()
    {
        if (!$this->auth->getIdentity()) {
            return $this->notFoundAction();
        }
        $csrf = new Csrf(['name' => 'express_alt_text_csrf', 'timeout' => 3600]);
        $request = $this->getRequest();
        $page = max(1, min(100000, (int) $this->params()->fromQuery('page', 1)));
        $filter = (string) $this->params()->fromQuery('filter', 'all');
        if (!in_array($filter, ['all', 'missing', 'filled', 'unreviewed', 'reviewed'], true)) {
            $filter = 'all';
        }
        $search = trim((string) $this->params()->fromQuery('search', ''));
        $search = mb_substr($search, 0, 150);
        $notice = null;
        $error = null;
        $failures = [];
        $submitted = [];
        $reviewed = $this->settings->get('express_alt_text_reviews', []);
        if (!is_array($reviewed)) {
            $reviewed = [];
        }

        if ($request->isPost()) {
            try {
                $post = $request->getPost();
                if (!$csrf->isValid((string) $post->get('express_csrf', ''))) {
                    $error = 'Security token expired. Reload the page and try again.';
                } else {
                    $entries = $post->get('alt_texts', []);
                    $reviewEntries = $post->get('reviewed', []);
                    $originalEntries = $post->get('original_alts', []);
                    if (!is_array($entries) || count($entries) > 30 || !is_array($reviewEntries) || !is_array($originalEntries)) {
                        $error = 'Invalid batch. No changes were saved.';
                    } else {
                        $saved = 0;
                        $reviewsChanged = 0;
                        foreach ($entries as $key => $value) {
                            $id = filter_var($key, FILTER_VALIDATE_INT);
                            if (!$id || !is_scalar($value)) {
                                $failures[] = (string) $key;
                                continue;
                            }
                            $alt = (string) $value;
                            $submitted[$id] = $alt;
                            if (strlen($alt) > 10000) {
                                $failures[] = $id . ' (text too long)';
                                continue;
                            }
                            $media = $this->em->find(Media::class, $id);
                            if (!$media || !$this->acl->userIsAllowed($media, 'update')) {
                                $failures[] = $id . ' (not permitted)';
                                continue;
                            }
                            $current = (string) $media->getAltText();
                            // Prevent overwriting changes made by another editor since page load.
                            if (!array_key_exists($id, $originalEntries) || !is_scalar($originalEntries[$id]) || (string) $originalEntries[$id] !== $current) {
                                $failures[] = $id . ' (changed by another editor; reload)';
                                continue;
                            }
                            if ($current !== $alt) {
                                try {
                                    $this->api->update('media', $id, ['o:alt_text' => $alt], [], ['isPartial' => true]);
                                    $saved++;
                                    unset($submitted[$id]);
                                    unset($reviewed[(string) $id]); // Edited text must be reviewed again.
                                } catch (\Throwable $e) {
                                    error_log('ExpressAltText save failed for media ' . $id . ': ' . $e->getMessage());
                                    $failures[] = (string) $id;
                                    continue;
                                }
                            }
                            $wantsReview = isset($reviewEntries[$id]) && (string) $reviewEntries[$id] === '1';
                            // Do not mark empty text reviewed; decorative images need separate classification.
                            if ($wantsReview && trim($alt) !== '') {
                                if (!isset($reviewed[(string) $id])) {
                                    $reviewed[(string) $id] = ['date' => gmdate('c')];
                                    $reviewsChanged++;
                                }
                            } elseif (isset($reviewed[(string) $id])) {
                                unset($reviewed[(string) $id]);
                                $reviewsChanged++;
                            }
                        }
                        $this->settings->set('express_alt_text_reviews', $reviewed);
                        $notice = $saved . ' description(s) saved; ' . $reviewsChanged . ' review status(es) updated.';
                        if ($failures) {
                            $error = 'Some records could not be saved: ' . implode(', ', $failures) . '. Your unsaved descriptions remain visible where possible.';
                        } elseif ($saved || $reviewsChanged) {
                            // Redirect to the same page, avoiding duplicate POST on refresh.
                            $this->flashMessenger()->addSuccessMessage($notice);
                            return $this->redirect()->toRoute('express-alt-text-dashboard', [], ['query' => ['page' => $page, 'filter' => $filter, 'search' => $search]]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('ExpressAltText batch request failed: ' . $e->getMessage());
                $error = 'Could not process the save request. Check the Omeka S error log.';
            }
        }

        // A lightweight scalar query allows accurate counts and filtered pagination.
        // Omeka media display titles may come from linked items and are not searchable here.
        $qb = $this->em->createQueryBuilder()
            ->select('m.id AS id', 'm.altText AS alt')
            ->from(Media::class, 'm')
            ->where('m.mediaType LIKE :imageType')
            ->setParameter('imageType', 'image/%')
            ->orderBy('m.id', 'DESC');
        if ($search !== '') {
            if (ctype_digit($search)) {
                $qb->andWhere('(m.id = :searchId OR m.altText LIKE :searchText)')
                    ->setParameter('searchId', (int) $search)
                    ->setParameter('searchText', '%' . $search . '%');
            } else {
                $qb->andWhere('m.altText LIKE :searchText')
                    ->setParameter('searchText', '%' . $search . '%');
            }
        }
        $rows = $qb->getQuery()->getArrayResult();
        $counts = ['all' => count($rows), 'missing' => 0, 'filled' => 0, 'reviewed' => 0, 'unreviewed' => 0];
        $matchedIds = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $filled = trim((string) ($row['alt'] ?? '')) !== '';
            $isReviewed = $filled && isset($reviewed[(string) $id]);
            $counts[$filled ? 'filled' : 'missing']++;
            $counts[$isReviewed ? 'reviewed' : 'unreviewed']++;
            if ($filter === 'all' || ($filter === 'missing' && !$filled) || ($filter === 'filled' && $filled)
                || ($filter === 'reviewed' && $isReviewed) || ($filter === 'unreviewed' && !$isReviewed)) {
                $matchedIds[] = $id;
            }
        }
        $totalMatching = count($matchedIds);
        $totalPages = max(1, (int) ceil($totalMatching / 30));
        $page = min($page, $totalPages);
        $pageIds = array_slice($matchedIds, ($page - 1) * 30, 30);
        $mediaList = [];
        foreach ($pageIds as $id) {
            $media = $this->em->find(Media::class, $id);
            if ($media && $this->acl->userIsAllowed($media, 'view')) {
                $mediaList[] = $media;
            }
        }
        $hasNext = $page < $totalPages;
        return new ViewModel(compact('mediaList', 'page', 'filter', 'search', 'csrf', 'notice', 'error', 'hasNext', 'submitted', 'reviewed', 'counts', 'totalPages', 'totalMatching'));
    }
}
