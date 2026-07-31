<?php

namespace App\Entity;

enum MediaAttachmentType: string
{
    case AVATAR = 'avatar';
    case PERSON_PHOTO = 'person_photo';
}
