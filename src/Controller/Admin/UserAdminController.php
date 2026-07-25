<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\Admin\UserAdminForm;
use App\Repository\LoginRepository;
use App\Repository\UserRepository;
use Pagerfanta\Doctrine\Collections\CollectionAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/user', name: 'admin_user_')]
#[IsGranted('ROLE_SUPERUSER')]
class UserAdminController extends AbstractController
{
    public function __construct(private readonly UserRepository $userRepository)
    {}

    #[Route(path: '/', name: 'list')]
    public function index(Request $request): Response
    {
        $template = $request->query->get('ajax') ? '_list.html.twig' : 'list.html.twig';

        return $this->render('admin/user/'.$template, [
            'users' => $this->userRepository->createAlphabeticalUserQueryBuilder()->getQuery()->execute(),
        ]);
    }

    #[Route(path: '/edit/{id}', name: 'edit')]
    public function edit(Request $request, User $_user, #[MapQueryParameter] int $page = 1): Response
    {
        $form = $this->createForm(UserAdminForm::class, $_user);

        $pager = Pagerfanta::createForCurrentPageWithMaxPerPage(new CollectionAdapter($_user->logins), $page, 15);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $_user */
            $_user = $form->getData();

            // if current user is a superuser, keep it that way (ignore form input)
            if ($this->getUser()->getUserIdentifier() === $_user->getUserIdentifier() && $this->isGranted('ROLE_SUPERUSER')) {
                $_user->roles = ['ROLE_SUPERUSER'];
            }

            $this->userRepository->save($_user, true);

            $this->addFlash('success', $_user->getUserIdentifier().' user updated.');

            if ($request->request->get('ajax')) {
                return new Response(null, Response::HTTP_NO_CONTENT);
            }

            return $this->redirectToRoute('admin_user_list');
        }

        $template = $request->query->get('ajax') ? '_form.html.twig' : 'edit.html.twig';

        return $this->render('admin/user/'.$template, [
            'form' => $form,
            '_user' => $_user,
            'logins' => $pager,
        ]);
    }

    #[Route(path: '/new', name: 'new')]
    public function new(Request $request): Response
    {
        $_user = new User();
        $form = $this->createForm(UserAdminForm::class, $_user);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $_user */
            $_user = $form->getData();

            $this->userRepository->save($_user, true);

            $this->addFlash('success', $_user->getUserIdentifier().' user created.');

            if ($request->request->get('ajax')) {
                $this->addFlash('danger', "Don't forget to send the activation email.");
                return new Response(null, Response::HTTP_NO_CONTENT);
            }

            return $this->redirectToRoute('admin_user_email_activation', [
                    'id' => $_user->id, ]
            );
        }

        $template = $request->query->get('ajax') ? '_form.html.twig' : 'new.html.twig';

        return $this->render('admin/user/'.$template, [
            'form' => $form,
        ]);
    }

    #[Route(path: '/delete/{id}', name: 'remove', methods: ['DELETE'])]
    public function remove(User $_user, LoginRepository $loginRepository): RedirectResponse
    {
        foreach ($_user->logins as $login) {
            $loginRepository->remove($login, true);
        }

        $this->userRepository->remove($_user, true);

        return $this->redirectToRoute('admin_user_list');
    }

    /**
     * @throws TransportExceptionInterface
     */
    #[Route(path: '/{id}/email_activation', name: 'email_activation')]
    public function emailUserActivation(User $_user, MailerInterface $mailer, string $kernelSecret): RedirectResponse
    {
        $hash = md5(time().$kernelSecret);
        $_user->confirmationHash = $hash;
        $this->userRepository->save($_user, true);

        $email = new TemplatedEmail()
            ->to($_user->email)
            ->subject('FileDrop Pro Account Activation')
            ->htmlTemplate('emails/user_activation.html.twig')
            ->context([
                'user' => $_user,
                'hash' => $hash,
            ])
        ;
        $mailer->send($email);

        $this->addFlash('success', 'Activation email sent.');

        return $this->redirectToRoute('admin_user_list');
    }
}
