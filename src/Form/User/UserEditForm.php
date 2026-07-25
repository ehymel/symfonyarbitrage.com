<?php

namespace App\Form\User;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserEditForm extends AbstractType
{
    /**
     * Used for user to edit their own profile;
     * Does not allow change of roles.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('lastName', null, [
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('firstName', null, [
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('credentials', null, [
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('cellNumber', TelType::class, [
                'label' => 'Cell #',
                'row_attr' => ['class' => 'form-floating mb-2'],
                'help' => 'Your cell number is used only for 2FA; your information will not be shared with anyone.',
                'help_attr' => ['class' => 'ms-2'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'validation_groups' => ['Default', 'EditUser'],
        ]);
    }
}
