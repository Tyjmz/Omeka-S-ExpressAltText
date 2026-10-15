<?php
namespace ExpressAltText\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Laminas\Form\Element\Csrf;
use Omeka\Entity\Media;

class AdminController extends AbstractActionController
{
    private $em;
    private $acl;
    private $auth;

    public function __construct($em, $acl, $auth)
    {
        $this->em = $em;
        $this->acl = $acl;
        $this->auth = $auth;
    }

    public function indexAction()
    {
        $user = $this->auth->getIdentity();
        if (!$user) return $this->notFoundAction();
        $csrf = new Csrf('express_csrf');
        $csrf->setOptions(['csrf_options' => ['timeout' => 3600]]);
        $request = $this->getRequest();
        $notice = null;
        $error = null;
        if ($request->isPost()) {
            $post = $request->getPost();
            $csrf->setValue($post->get('express_csrf'));
            if (!$csrf->isValid()) {
                $error = 'Security token expired. Reload the page and try again.';
            } else {
                $id = filter_var($post->get('media_id'), FILTER_VALIDATE_INT);
                $media = $id ? $this->em->find(Media::class, $id) : null;
                if (!$media || !$this->acl->userIsAllowed($media, 'update')) {
                    $error = 'You do not have permission to edit this media.';
                } elseif (strlen((string) $post->get('alt_text')) > 10000) {
                    $error = 'Alt text exceeds the 10,000 character limit.';
                } else {
                    $alt = (string) $post->get('alt_text', '');
                    // Use the native media field that Omeka S renders as o:alt_text.
                    $media->setAltText($alt);
                    $this->em->flush();
                    $notice = 'Alt text saved for media #' . $id . '.';
                }
            }
        }
        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $filter = (string) $this->params()->fromQuery('filter', 'all');
        if (!in_array($filter, ['all', 'missing', 'filled'], true)) $filter = 'all';
        $qb = $this->em->createQueryBuilder()
            ->select('m')->from(Media::class, 'm')
            ->where('m.mediaType LIKE :imageType')
            ->setParameter('imageType', 'image/%')
            ->orderBy('m.id', 'DESC');
        if ($filter === 'missing') $qb->andWhere("m.altText IS NULL OR m.altText = ''");
        if ($filter === 'filled') $qb->andWhere("m.altText IS NOT NULL AND m.altText <> ''");
        $qb->setFirstResult(($page - 1) * 30)->setMaxResults(30);
        $mediaList = $qb->getQuery()->getResult();
        // Apply view authorization even when querying the ORM directly.
        $mediaList = array_values(array_filter($mediaList, function ($m) {
            return $this->acl->userIsAllowed($m, 'view');
        }));
        return new ViewModel(compact('mediaList', 'page', 'filter', 'csrf', 'notice', 'error'));
    }
}
