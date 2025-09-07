<?php

declare(strict_types=1);

namespace Neox\FireGeolocatorBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BannedController extends AbstractController
{
    #[Route(path: '/geolocator/banned', name: 'geolocator_banned', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@NeoxFireGeolocator/banned.html.twig');
    }
}
