<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use League\Bundle\OAuth2ServerBundle\Model\AbstractClient;

/** OAuth client identifiers may be CIMD HTTPS URLs rather than generated IDs. */
#[ORM\Entity]
#[ORM\Table(name: 'oauth2_client')]
#[ORM\AttributeOverrides([new ORM\AttributeOverride(name: 'name', column: new ORM\Column(length: 200))])]
class OAuthClient extends AbstractClient
{
    /** @var non-empty-string */
    #[ORM\Id]
    #[ORM\Column(length: 2048)]
    protected string $identifier;
}
