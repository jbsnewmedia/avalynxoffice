<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Address;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<Address>
 */
class ContactFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, [
                'label' => 'contact.form.firstname',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('lastname', TextType::class, [
                'label' => 'contact.form.lastname',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('name', TextType::class, [
                'label' => 'contact.form.displayname',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'contact.form.email',
                'attr' => ['class' => 'form-control'],
                'empty_data' => '',
                'constraints' => [
                    new NotBlank(message: 'contact.form.email_required'),
                    new Email(message: 'contact.form.email_invalid'),
                ],
            ])
            ->add('company', TextType::class, [
                'label' => 'contact.form.company',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('businessPhone', TelType::class, [
                'label' => 'contact.form.business_phone',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('mobilePhone', TelType::class, [
                'label' => 'contact.form.mobile_phone',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('homePhone', TelType::class, [
                'label' => 'contact.form.home_phone',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ]);

        if (!empty($options['accounts'])) {
            $builder->add('account', EntityType::class, [
                'class' => Account::class,
                'choices' => $options['accounts'],
                'choice_label' => 'email',
                'label' => 'contact.form.account',
                'mapped' => false,
                'required' => true,
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank()],
            ]);
        }

        $builder->add('save', SubmitType::class, [
            'label' => 'common.save',
            'attr' => ['class' => 'btn-primary'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Address::class,
            'accounts' => null,
        ]);

        $resolver->setAllowedTypes('accounts', ['null', 'array']);
    }
}
