<?php

namespace App\Form\Admin;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserAdminForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('lastName', null, [
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('firstName', null, [
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('credentials', null, [
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('email', EmailType::class, [
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('isVisible', CheckboxType::class, [
                'label' => 'Is this user visible in user lists?',
                'required' => false,
            ])
            ->add('isActivated', CheckboxType::class, [
                'label' => 'Has this user activated his/her account?',
                'disabled' => true,
                'required' => false,
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Role of this user?',
                'choices' => [
                    'Admin' => 'ROLE_ADMIN',
                    'User' => 'ROLE_USER',
                ],
                'multiple' => true,
                'expanded' => true, // make it checkboxes
                'label_attr' => ['class' => 'checkbox-inline'],
            ])
            ->add('cellNumber', TelType::class, [
                'label' => 'Cell #',
                'row_attr' => ['class' => 'form-floating mb-2'],
            ])
            ->add('status', HiddenType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
