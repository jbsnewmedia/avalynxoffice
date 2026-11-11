<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Service\Eas\EasPolicyService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\Type as TypeConstraint;

/**
 * Global EAS device policies, edited on the admin EAS policy page.
 * Every field is unmapped: the controller translates the submitted
 * values into policy deviations and hands them to EasPolicyService.
 * All fields default to off, so a fresh installation provisions
 * devices without any restriction.
 *
 * @extends AbstractType<mixed>
 *
 * @param array{stored: array<string, string>} $options
 */
class EasPolicyType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $stored */
        $stored = $options['stored'];

        foreach (EasPolicyService::TOGGLES as $key => $onValue) {
            $builder->add($key, CheckboxType::class, [
                'label' => 'admin.eas_policy.toggle_'.$key,
                'help' => 'admin.eas_policy.toggle_'.$key.'_help',
                'mapped' => false,
                'required' => false,
                'data' => ($stored[$key] ?? null) === $onValue,
                'label_attr' => ['class' => 'form-label'],
            ]);
        }

        foreach (EasPolicyService::NUMBERS as $key => $definition) {
            $storedValue = $stored[$key] ?? '';

            $builder->add($key, IntegerType::class, [
                'label' => 'admin.eas_policy.number_'.$key,
                'help' => 'admin.eas_policy.number_'.$key.'_help',
                'mapped' => false,
                'required' => false,
                'data' => ctype_digit($storedValue) ? (int) $storedValue : null,
                'attr' => [
                    'class' => 'form-control',
                    'min' => $definition['min'],
                    'max' => $definition['max'],
                    'placeholder' => 'admin.eas_policy.number_'.$key.'_placeholder',
                ],
                'constraints' => [
                    new TypeConstraint('numeric'),
                    new GreaterThanOrEqual($definition['min']),
                    new LessThanOrEqual($definition['max']),
                ],
            ]);
        }

        $builder->add('save', SubmitType::class, [
            'label' => 'common.save',
            'attr' => ['class' => 'btn btn-primary'],
        ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'stored' => [],
        ]);

        $resolver->setAllowedTypes('stored', 'array');
    }
}
