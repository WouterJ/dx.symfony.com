<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use AppBundle\Entity\Issue;

class DefaultController extends Controller
{
    /**
     * @Route("/", name = "homepage")
     */
    public function indexAction()
    {
        $new = $this->getRepository()->findByStatus(Issue::STATUS_NEW, array('createdAt' => 'DESC'));
        $discussing = $this->getRepository()->findByStatus(Issue::STATUS_DISCUSSING, array('createdAt' => 'DESC'));
        $wip = $this->getRepository()->findByStatus(Issue::STATUS_WIP, array('createdAt' => 'DESC'));
        $finished = $this->getRepository()->findByStatus(Issue::STATUS_FINISHED, array('createdAt' => 'DESC'));

        return $this->render("index.html.twig", array(
            'new'        => $new,
            'discussing' => $discussing,
            'wip'        => $wip,
            'finished'   => $finished,
        ));
    }

    /**
     * @Route("/assign", name = "assign")
     * @Method({"POST"})
     */
    public function assignIssueAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $issue = $this->getRepository()->find($request->get('issue_id'));
        $issue->setAssignedTo(trim($request->get('assignee')));
        $issue->setStatus(Issue::STATUS_WIP);

        $em->persist($issue);
        $em->flush();

        return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * @Route("/add_pull_request", name = "add_pr")
     * @Method({"POST"})
     */
    public function addPullRequestAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();

        $issue = $this->getRepository()->find($request->get('issue_id'));
        $issue->setPullRequest(trim($request->get('url')));

        $em->persist($issue);
        $em->flush();

        return $this->redirect($this->generateUrl('homepage'));
    }

    private function getRepository()
    {
        return $this->getDoctrine()->getManager()->getRepository('AppBundle:Issue');
    }
}
