<?php

namespace App\Controller\Security;

use App\Entity\User;
use App\Form\User\UserEditForm;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('user', name: 'user_')]
class UserController extends AbstractController
{
    public function __construct(#[Autowire(param: 'kernel.secret')]
                                private readonly string $kernelSecret) {}

    #[Route(path: '/edit', name: 'edit')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function edit(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $form = $this->createForm(UserEditForm::class, $user);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $form->getData();

            if ($user->plainPassword) {
                $user->password = $passwordHasher->hashPassword($user, $user->plainPassword);
            }

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'User profile updated.');

            return $this->redirectToRoute('user_edit');
        }

        return $this->render('user/edit.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/activate/{id}/{hash}', name: 'activate')]
    public function activate(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $submittedHash = $request->attributes->get('hash');
        $savedHash = $user->confirmationHash;

        if ($submittedHash === $savedHash) {
            // Display "is activated" in admin user edit page
            // has no effect on login!!
            $user->isActivated = true;

            // create new confirmation hash to pass to set_password script (for security)
            $hash = md5(time().$this->kernelSecret);
            $user->confirmationHash = $hash;

            $em->persist($user);
            $em->flush();

            $this->addFlash('success', 'Your account has been activated.');

            return $this->redirectToRoute('user_password_reset', [
                'id' => $user->id,
                'hash' => $hash,
            ]);
        }

        return $this->render('user/activation_failed.html.twig');
    }
}
