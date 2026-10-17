<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Entity\CalendarEvent;
use AvalynxOffice\Entity\Mailbox;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<CalendarEvent>
 */
class CalendarEventFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'calendar.form.title',
                'empty_data' => '',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(message: 'calendar.form.title_required'),
                ],
            ])
            ->add('isAllDay', CheckboxType::class, [
                'label' => 'calendar.form.all_day',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('startAt', DateTimeType::class, [
                'label' => 'calendar.form.start',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(message: 'calendar.form.start_required'),
                ],
            ])
            ->add('endAt', DateTimeType::class, [
                'label' => 'calendar.form.end',
                'widget' => 'single_text',
                'html5' => true,
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('location', TextType::class, [
                'label' => 'calendar.form.location',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'calendar.form.description',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 4],
            ])
            ->add('busyStatus', ChoiceType::class, [
                'label' => 'calendar.form.busy',
                'required' => false,
                'placeholder' => 'calendar.form.busy_default',
                'choices' => [
                    'calendar.busy.0' => 0,
                    'calendar.busy.1' => 1,
                    'calendar.busy.2' => 2,
                    'calendar.busy.3' => 3,
                    'calendar.busy.4' => 4,
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('sensitivity', ChoiceType::class, [
                'label' => 'calendar.form.sensitivity',
                'required' => false,
                'placeholder' => 'calendar.form.sensitivity_default',
                'choices' => [
                    'calendar.sensitivity.0' => 0,
                    'calendar.sensitivity.1' => 1,
                    'calendar.sensitivity.2' => 2,
                    'calendar.sensitivity.3' => 3,
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('reminder', IntegerType::class, [
                'label' => 'calendar.form.reminder',
                'required' => false,
                'attr' => ['class' => 'form-control', 'min' => 0],
            ]);

        if (!empty($options['mailboxes'])) {
            $builder->add('mailbox', EntityType::class, [
                'class' => Mailbox::class,
                'choices' => $options['mailboxes'],
                'choice_label' => 'email',
                'label' => 'contact.form.mailbox',
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
            'data_class' => CalendarEvent::class,
            'mailboxes' => null,
        ]);

        $resolver->setAllowedTypes('mailboxes', ['null', 'array']);
    }
}
