<?php
namespace ExpressAltText\Controller;

use Laminas\Form\Element\Csrf;
use Laminas\Mvc\Controller\AbstractActionController;
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

        $csrf = new Csrf('express_csrf');
        $csrf->setOptions(['csrf_options' => ['timeout' => 3600]]);
        $request = $this->getRequest();
        $notice = $request->getQuery('saved') === '1' ? 'Alt text saved.' : null;
        $error = null;
        $page = max(1, min(100000, (int) $this->params()->fromQuery('page', 1)));
        $filter = (string) $this->params()->fromQuery('filter', 'all');
        if (!in_array($filter, ['all', 'missing', 'filled'], true)) {
            $filter = 'all';
        }

        if ($request->isPost()) {
            $post = $request->getPost();
            $csrf->setValue($post->get('express_csrf'));
            $id = filter_var($post->get('media_id'), FILTER_VALIDATE_INT);
            $alt = (string) $post->get('alt_text', '');
            if (!$csrf->isValid()) {
                $error = 'Security token expired. Reload the page and try again.';
            } elseif (!$id || !($media = $this->em->find(Media::class, $id))
                || !$this->acl->userIsAllowed($media, 'update')) {
                $error = 'You do not have permission to edit this media.';
            } elseif (strlen($alt) > 10000) {
                $error = 'Alt text exceeds the 10,000-byte limit.';
            } else {
                try {
                    // Use Omeka's API update pipeline, not a direct database flush.
                    // isPartial keeps all unrelated media fields unchanged.
                    $this->api->update('media', $id, ['o:alt_text' => $alt], [], ['isPartial' => true]);
                    return $this->redirect()->toRoute('express-alt-text-dashboard', [], [
                        'query' => ['page' => $page, 'filter' => $filter, 'saved' => '1'],
                    ]);
                } catch (\Throwable $e) {
                    error_log('ExpressAltText media update failed: ' . $e->getMessage());
                    $error = 'Could not save alt text. Check the Omeka S error log for details.';
                }
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

        return new ViewModel(compact('mediaList', 'page', 'filter', 'csrf', 'notice', 'error', 'hasNext'));
    }
}
