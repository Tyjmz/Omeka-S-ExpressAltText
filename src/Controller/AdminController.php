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

    public function __construct($api, $em, $acl, $auth)
    {
        $this->api = $api;
        $this->em = $em;
        $this->acl = $acl;
        $this->auth = $auth;
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
        if (!in_array($filter, ['all', 'missing', 'filled'], true)) {
            $filter = 'all';
        }
        $notice = null;
        $error = null;
        $failures = [];
        $submitted = [];

        if ($request->isPost()) {
            try {
                $post = $request->getPost();
                $token = (string) $post->get('express_csrf', '');
                if (!$csrf->isValid($token)) {
                    $error = 'Security token expired. Reload the page and try again.';
                } else {
                    $entries = $post->get('alt_texts', []);
                    if (!is_array($entries) || count($entries) > 30) {
                        $error = 'Invalid batch. No changes were saved.';
                    } else {
                        $saved = 0;
                        foreach ($entries as $key => $value) {
                            $id = filter_var($key, FILTER_VALIDATE_INT);
                            if (!$id || !is_scalar($value)) {
                                $failures[] = (string) $key;
                                continue;
                            }
                            $alt = (string) $value;
                            $submitted[$id] = $alt;
                            if (strlen($alt) > 10000) {
                                $failures[] = (string) $id . ' (text too long)';
                                continue;
                            }
                            $media = $this->em->find(Media::class, $id);
                            if (!$media || !$this->acl->userIsAllowed($media, 'update')) {
                                $failures[] = (string) $id . ' (not permitted)';
                                continue;
                            }
                            if ((string) $media->getAltText() === $alt) {
                                continue;
                            }
                            try {
                                $this->api->update('media', $id, ['o:alt_text' => $alt], [], ['isPartial' => true]);
                                $saved++;
                                unset($submitted[$id]);
                            } catch (\Throwable $e) {
                                error_log('ExpressAltText save failed for media ' . $id . ': ' . $e->getMessage());
                                $failures[] = (string) $id;
                            }
                        }
                        if ($saved) {
                            $notice = $saved . ' image description(s) saved.';
                        }
                        if ($failures) {
                            $error = 'Some changes could not be saved (media IDs: ' . implode(', ', $failures) . '). See Omeka S logs for details.';
                        } elseif (!$saved) {
                            $notice = 'No changes needed saving.';
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log('ExpressAltText batch request failed: ' . $e->getMessage());
                $error = 'Could not process the save request. Check the Omeka S error log.';
            }
        }

        $qb = $this->em->createQueryBuilder()
            ->select('m')->from(Media::class, 'm')
            ->where('m.mediaType LIKE :imageType')
            ->setParameter('imageType', 'image/%')
            ->orderBy('m.id', 'DESC');
        if ($filter === 'missing') {
            $qb->andWhere("(m.altText IS NULL OR m.altText = '')");
        } elseif ($filter === 'filled') {
            $qb->andWhere("(m.altText IS NOT NULL AND m.altText <> '')");
        }
        $qb->setFirstResult(($page - 1) * 30)->setMaxResults(31);
        $results = $qb->getQuery()->getResult();
        $hasNext = count($results) > 30;
        $results = array_slice($results, 0, 30);
        $mediaList = array_values(array_filter($results, function ($media) {
            return $this->acl->userIsAllowed($media, 'view');
        }));
        return new ViewModel(compact('mediaList', 'page', 'filter', 'csrf', 'notice', 'error', 'hasNext', 'submitted'));
    }
}
