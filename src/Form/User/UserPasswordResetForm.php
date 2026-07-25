<?php

namespace App\Form\User;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserPasswordResetForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'attr' => [
                    'id' => 'current-password',
                    'name' => 'current_password',
                    'required' => true,
                    'autocomplete' => 'current-password',
                    'data-password-update-target' => 'currentPasswordInput',
                ],
                'row_attr' => ['class' => 'form-floating mb-4'],
                'help' => 'Required to decrypt and safely transfer your private E2EE key envelope.',
            ])
            ->add('newPassword', PasswordType::class, [
                'attr' => [
                    'id' => 'new-password',
                    'name' => 'new_password',
                    'required' => true,
                    'autocomplete' => 'new-password',
                    'data-password-update-target' => 'newPasswordInput',
                ],
                'row_attr' => ['class' => 'form-floating mb-4'],
                'help' => 'Must be at least 8 characters long.',
            ])
            ->add('confirmPassword', PasswordType::class, [
                'attr' => [
                    'id' => 'confirm-password',
                    'name' => 'confirm_password',
                    'required' => true,
                    'autocomplete' => 'new-password',
                    'data-password-update-target' => 'confirmPasswordInput',
                ],
                'row_attr' => ['class' => 'form-floating mb-4'],
                'help' => 'Must match the new password.',
            ])
            ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {}
}
