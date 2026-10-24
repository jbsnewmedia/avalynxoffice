<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\AccountRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Base form of a connector (name, domain, default account). The technical
 * settings are provided by connector plugins on the settings page.
 *
 * @extends AbstractType<Connector>
 */
class ConnectorType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'connector.name',
                'required' => true,
                'constraints' => [new NotBlank()],
            ])
            ->add('domain', TextType::class, [
                'label' => 'connector.domain',
                'required' => true,
                'help' => 'connector.domain_help',
                'empty_data' => '',
                'constraints' => [new NotBlank()],
            ])
            ->add('defaultAccount', EntityType::class, [
                'label' => 'connector.default_account',
                'class' => Account::class,
                'choice_label' => static fn (Account $account): string => sprintf('%s (%s)', $account->getName(), $account->getEmail()),
                'query_builder' => static fn (AccountRepository $repository) => $repository->createQueryBuilder('a')->orderBy('a.name', 'ASC'),
                'placeholder' => 'connector.no_default_account',
                'required' => false,
                'help' => 'connector.default_account_help',
            ])
            ->add('save', SubmitType::class, [
                'label' => 'common.save',
                'attr' => ['class' => 'btn btn-primary'],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Connector::class,
        ]);
    }
}
