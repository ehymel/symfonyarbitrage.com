<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin')]
#[IsGranted('ROLE_SUPERUSER')]
class DefaultAdminController extends AbstractController
{
    #[Route(path: '/', name: 'admin_home')]
    public function index(): Response
    {
        return $this->render('admin/admin_base.html.twig');
    }
}
