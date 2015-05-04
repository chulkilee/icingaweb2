<?php
/* Icinga Web 2 | (c) 2013-2015 Icinga Development Team | GPLv2+ */

use Icinga\Module\Monitoring\Controller;
use Icinga\Module\Monitoring\Forms\Command\Object\DeleteCommentCommandForm;
use Icinga\Web\Url;
use Icinga\Web\Widget\Tabextension\DashboardAction;
use Icinga\Data\Filter\Filter;

/**
 * Display detailed information about a comment
 */
class Monitoring_CommentsController extends Controller
{
    protected $comments;

    public function init()
    {
        $this->filter = Filter::fromQueryString(str_replace(
                'comment_id',
                'comment_internal_id',
                (string)$this->params
        ));
        $this->comments = $this->backend->select()->from('comment', array(
            'id'         => 'comment_internal_id',
            'objecttype' => 'comment_objecttype',
            'comment'    => 'comment_data',
            'author'     => 'comment_author_name',
            'timestamp'  => 'comment_timestamp',
            'type'       => 'comment_type',
            'persistent' => 'comment_is_persistent',
            'expiration' => 'comment_expiration',
            'host_name',
            'service_description',
            'host_display_name',
            'service_display_name'
        ))->addFilter($this->filter)->getQuery()->fetchAll();
        
        if (false === $this->comments) {
            throw new Zend_Controller_Action_Exception($this->translate('Comment not found'));
        }
         
        $this->getTabs()
            ->add(
                'comments',
                array(
                    'title' => $this->translate(
                        'Display detailed information about multiple comments.'
                    ),
                    'icon' => 'comment',
                    'label' => $this->translate('Comments'),
                    'url'   =>'monitoring/comments/show'
                )
        )->activate('comments')->extend(new DashboardAction());
    }
    
    public function showAction()
    {
        $this->view->comments = $this->comments;
        $this->view->listAllLink = Url::fromPath('monitoring/list/comments')
                ->setQueryString($this->filter->toQueryString());
        $this->view->removeAllLink = Url::fromPath('monitoring/comments/remove-all')
                ->setParams($this->params);
    }

    public function removeAllAction()
    {
        $this->assertPermission('monitoring/command/comment/delete');
        $this->view->comments = $this->comments;
        $this->view->listAllLink = Url::fromPath('monitoring/list/comments')
                ->setQueryString($this->filter->toQueryString());
        $delCommentForm = new DeleteCommentCommandForm();
        $delCommentForm->setTitle($this->view->translate('Remove all Comments'));
        $delCommentForm->addDescription(sprintf(
            $this->translate('Confirm removal of %d comments.'),
            count($this->comments)
        ));
        $delCommentForm->setObjects($this->comments)
                ->setRedirectUrl(Url::fromPath('monitoring/list/downtimes'))
                ->handleRequest();
        $this->view->delCommentForm = $delCommentForm;
    }
}