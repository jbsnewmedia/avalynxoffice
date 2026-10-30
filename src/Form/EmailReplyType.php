<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

/**
 * @extends AbstractType<Email>
 */
class EmailReplyType extends AbstractType
{
    public function __construct(
        private readonly AttachmentStorage $attachmentStorage,
    ) {
    }

    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $senderChoices = is_array($options['sender_choices'] ?? null) ? $options['sender_choices'] : [];
        $maxAttachmentBytes = max(1, $this->attachmentStorage->getMaxBytes());

        $builder
            ->add('from', ChoiceType::class, [
                'label' => 'email.from',
                'mapped' => false,
                'required' => false,
                'choices' => $senderChoices,
                'placeholder' => empty($senderChoices) ? false : 'email.from',
            ])
            ->add('to', TextType::class, [
                'label' => 'email.to',
                'mapped' => false,
            ])
            ->add('cc', TextType::class, [
                'label' => 'email.cc',
                'mapped' => false,
                'required' => false,
            ])
            ->add('bcc', TextType::class, [
                'label' => 'email.bcc',
                'mapped' => false,
                'required' => false,
            ])
            ->add('replyTo', TextType::class, [
                'label' => 'email.reply_to',
                'mapped' => false,
                'required' => false,
            ])
            ->add('subject', TextType::class, [
                'label' => 'email.subject',
            ])
            ->add('content', TextareaType::class, [
                'label' => 'email.content',
                'mapped' => false,
                'attr' => ['rows' => 15],
            ])
            ->add('scheduleLater', CheckboxType::class, [
                'label' => 'email.schedule_enable',
                'mapped' => false,
                'required' => false,
            ])
            ->add('scheduledAt', DateTimeType::class, [
                'label' => 'email.scheduled_at',
                'widget' => 'single_text',
                'required' => false,
                'mapped' => false,
            ])
            ->add('retainedAttachmentIds', HiddenType::class, [
                'mapped' => false,
                'required' => false,
            ])
            ->add('attachments', FileType::class, [
                'label' => 'email.attachments',
                'mapped' => false,
                'required' => false,
                'multiple' => true,
                'constraints' => [
                    new All([
                        new File(maxSize: $maxAttachmentBytes),
                    ]),
                ],
            ])
            ->add('send', SubmitType::class, [
                'label' => 'email.send_now',
                'attr' => ['class' => 'btn btn-primary'],
            ])
            ->add('saveDraft', SubmitType::class, [
                'label' => 'email.save_draft',
                'attr' => ['class' => 'btn btn-secondary'],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Email::class,
            'sender_choices' => [],
        ]);
        $resolver->setAllowedTypes('sender_choices', 'array');
    }
}
