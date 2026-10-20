<?php
namespace ExpressAltText\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\Validator\Csrf as CsrfValidator;
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

    /** Log the exception's origin, missing service name, and short trace. */
    private function logSaveException(\Throwable $e, $phase)
    {
        error_log(sprintf(
            'ExpressAltText v1.1.12 [%s]: %s: %s at %s:%d\n%s',
            $phase,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
    }

    /** Exact fingerprint of the approved native alt text (including whitespace). */
    private function altFingerprint($alt)
    {
        return hash('sha256', (string) $alt);
    }

    /**
     * Invalidate stale reviews, including legacy records without a fingerprint.
     * Retain the old date and fingerprint for future audit/history support.
     * Once invalidated, restoring old text does NOT silently restore approval.
     */
    private function reconcileReviews(array &$records)
    {
        $ids = [];
        foreach ($records as $key => $record) {
            if (is_array($record) && empty($record['invalidated_at'])) {
                $id = filter_var($key, FILTER_VALIDATE_INT);
                if ($id && $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }
        if (!$ids) {
            return false;
        }
        $current = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $rows = $this->em->createQueryBuilder()
                ->select('m.id AS id', 'm.altText AS alt')
                ->from(Media::class, 'm')
                ->where('m.id IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getQuery()->getArrayResult();
            foreach ($rows as $row) {
                $current[(string) $row['id']] = (string) ($row['alt'] ?? '');
            }
        }
        $changed = false;
        foreach ($ids as $id) {
            $key = (string) $id;
            $record = $records[$key];
            $validHash = isset($record['hash']) && is_string($record['hash'])
                && strlen($record['hash']) === 64;
            if (!$validHash || !isset($current[$key]) || trim($current[$key]) === ''
                || !hash_equals($record['hash'], $this->altFingerprint($current[$key]))) {
                $records[$key]['invalidated_at'] = gmdate('c');
                $records[$key]['invalidation_reason'] = !$validHash
                    ? 'legacy_review_without_fingerprint'
                    : 'native_alt_text_changed';
                $changed = true;
            }
        }
        return $changed;
    }

    private function activeReviews(array $records)
    {
        return array_filter($records, function ($record) {
            return is_array($record) && empty($record['invalidated_at'])
                && isset($record['hash']) && is_string($record['hash']);
        });
    }

    public function indexAction()
    {
        if (!$this->auth->getIdentity()) {
            return $this->notFoundAction();
        }
        // Validator (not Laminas\Form\Element\Csrf): the element has no isValid() method.
        $csrf = new CsrfValidator(['name' => 'express_alt_text_csrf', 'timeout' => 3600]);
        $request = $this->getRequest();
        $page = max(1, min(100000, (int) $this->params()->fromQuery('page', 1)));
        $filter = (string) $this->params()->fromQuery('filter', 'all');
        if (!in_array($filter, ['all', 'missing', 'filled', 'unreviewed', 'reviewed'], true)) {
            $filter = 'all';
        }
        $search = trim((string) $this->params()->fromQuery('search', ''));
        $search = mb_substr($search, 0, 150);
        $notice = null;
        // A successful POST redirects to GET. These query values are display-only;
        // they never authorize or perform a write. Clamp them to expected batch sizes.
        if (!$request->isPost() && $this->params()->fromQuery('express_result') === 'saved') {
            $reportedSaved = filter_var($this->params()->fromQuery('express_saved'), FILTER_VALIDATE_INT);
            $reportedReviews = filter_var($this->params()->fromQuery('express_reviews'), FILTER_VALIDATE_INT);
            if ($reportedSaved !== false && $reportedReviews !== false
                && $reportedSaved >= 0 && $reportedSaved <= 30
                && $reportedReviews >= 0 && $reportedReviews <= 30) {
                $notice = $reportedSaved . ' description(s) saved; '
                    . $reportedReviews . ' review status(es) updated.';
            }
        }
        $error = null;
        $failures = [];
        $submitted = [];
        $reviewed = $this->settings->get('express_alt_text_reviews', []);
        if (!is_array($reviewed)) {
            $reviewed = [];
        }
        // Reconcile against the current native media values on every request,
        // including when changes were made in Omeka's own media editor.
        try {
            if ($this->reconcileReviews($reviewed)) {
                $this->settings->set('express_alt_text_reviews', $reviewed);
            }
        } catch (\Throwable $e) {
            $this->logSaveException($e, 'external review reconciliation');
            // Fail closed: never show a potentially stale review as approved.
            $reviewed = [];
            $error = 'Could not verify existing review approvals. They are hidden until review validation succeeds. Check the PHP error log.';
        }
        $activeReviewed = $this->activeReviews($reviewed);

        if ($request->isPost()) {
            $savePhase = 'request validation';
            try {
                $post = $request->getPost();
                $savePhase = 'CSRF validation';
                if (!$csrf->isValid((string) $post->get('express_csrf', ''))) {
                    $error = 'Security token expired. Reload the page and try again.';
                } else {
                    $savePhase = 'batch input validation';
                    $entries = $post->get('alt_texts', []);
                    $reviewEntries = $post->get('reviewed', []);
                    $originalEntries = $post->get('original_alts', []);
                    $reviewConfirmations = $post->get('review_confirmed', []);
                    if (!is_array($entries) || count($entries) > 30 || !is_array($reviewEntries) || !is_array($originalEntries) || !is_array($reviewConfirmations)) {
                        $error = 'Invalid batch. No changes were saved.';
                    } else {
                        $saved = 0;
                        $reviewsChanged = 0;
                        foreach ($entries as $key => $value) {
                            $savePhase = 'processing media ' . (string) $key;
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
                            $textChanged = $current !== $alt;
                            $wasReviewed = isset($activeReviewed[(string) $id]);
                            if ($textChanged) {
                                try {
                                    $this->api->update('media', $id, ['o:alt_text' => $alt], [], ['isPartial' => true]);
                                    $saved++;
                                    unset($submitted[$id]);
                                } catch (\Throwable $e) {
                                    $this->logSaveException($e, 'native media API update for media ' . $id);
                                    $failures[] = (string) $id;
                                    continue;
                                }
                            }
                            $wantsReview = isset($reviewEntries[$id]) && (string) $reviewEntries[$id] === '1';
                            // For changed text, an explicit fresh approval is required.
                            // A stale checked checkbox alone must never restore an old review.
                            $explicitApproval = isset($reviewConfirmations[$id])
                                && (string) $reviewConfirmations[$id] === '1';
                            $newReviewed = $wantsReview && trim($alt) !== ''
                                && (!$textChanged || $explicitApproval);
                            if ($newReviewed) {
                                if (!$wasReviewed || $textChanged) {
                                    $reviewed[(string) $id] = [
                                        'date' => gmdate('c'),
                                        'hash' => $this->altFingerprint($alt),
                                    ];
                                }
                            } else {
                                if (isset($reviewed[(string) $id]) && empty($reviewed[(string) $id]['invalidated_at'])) {
                                    $reviewed[(string) $id]['invalidated_at'] = gmdate('c');
                                    $reviewed[(string) $id]['invalidation_reason'] = 'unreviewed_in_express_alt_text';
                                }
                            }
                            if ($wasReviewed !== $newReviewed || ($textChanged && $newReviewed)) {
                                $reviewsChanged++;
                            }

                        }
                        // Review bookkeeping is independent from native media writes.
                        // A settings failure must not conceal successful alt-text saves.
                        $savePhase = 'review settings persistence';
                        if ($reviewsChanged || $saved) {
                            try {
                                $this->settings->set('express_alt_text_reviews', $reviewed);
                            } catch (\Throwable $e) {
                                $this->logSaveException($e, 'review settings persistence');
                                $error = 'Alt-text changes may have saved, but review status could not be stored. Check the server error log.';
                                $failures[] = 'review status';
                            }
                        }
                        $notice = $saved . ' description(s) saved; ' . ($error ? 'review status not confirmed' : $reviewsChanged . ' review status(es) updated') . '.';
                        if ($failures) {
                            $error = 'Some operations failed: ' . implode(', ', $failures) . '. Review the affected records before continuing.';
                        } else {
                            // Post/Redirect/Get: a successful batch must not remain on a
                            // POST response. Do not use flashMessenger: that service lookup
                            // caused an exception on an earlier deployment.
                            return $this->redirect()->toRoute('express-alt-text-dashboard', [], [
                                'query' => [
                                    'page' => $page,
                                    'filter' => $filter,
                                    'search' => $search,
                                    'express_result' => 'saved',
                                    'express_saved' => $saved,
                                    'express_reviews' => $reviewsChanged,
                                ],
                            ]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $this->logSaveException($e, $savePhase);
                $error = 'Could not process the save request during ' . $savePhase . '. Details: ' . get_class($e) . '. Check the PHP error log for the exact missing service and source line.';
            }
        }

        // Only fingerprint-validated approvals contribute to filters and statistics.
        $reviewed = $this->activeReviews($reviewed);

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
