<?php

namespace Survos\StorageBundle\Controller;

use Aws\Result;
use Aws\S3\S3ClientInterface;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use Survos\StorageBundle\Service\StorageService;
use Symfony\Bridge\Twig\Attribute\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

class StorageController extends AbstractController
{
    public function __construct(
        private StorageService $storageService,
//        private #[AutowireIterator('flysystem.storage')] iterable $storages,
        private $simpleDatatablesInstalled = false,
    )
    {

    }

    private function checkSimpleDatatablesInstalled()
    {
        if (! $this->simpleDatatablesInstalled) {
            throw new \LogicException("This page requires SimpleDatatables\n composer req survos/simple-datatables-bundle");
        }
    }

    #[Route('/zones', name: 'survos_storage_zones', methods: ['GET'])]
    #[Template('@SurvosStorage/zones.html.twig')]
    public function zones(
    ): Response|array
    {
        $this->checkSimpleDatatablesInstalled();
        return [
            'adapters' => $this->storageService->getAdapters(),
            'zones' => $this->storageService->getZones()];
    }

    #[Route('/{zoneName}}/{path}/{fileName}/download', name: 'survos_storage_download', methods: ['GET'], requirements: ['path'=> ".+"])]
    #[Template('@SurvosStorage/zone.html.twig')]
    public function download(string $zoneName, string $path, string $fileName): Response
    {
        $response = $this->storageService->downloadFile($fileName,$path,$zoneName);
        return new Response($response); // eh
    }

    #[Route('/show/{zoneId}/{path}', name: 'survos_storage_view', methods: ['GET'], requirements: ['path'=> ".+"])]
    #[Template('@SurvosStorage/show.html.twig')]
    public function show(
        string $zoneId,
        string $path,
        #[MapQueryParameter] bool $signed=false,
    ): Response|array
    {
        $service = $this->storageService->getZone($zoneId);
        $directUrl = null;
        try {
            $directUrl = $signed
                ? $service->temporaryUrl($path, new \DateTimeImmutable('+60 minutes'))
                : $service->publicUrl($path);
        } catch (\Throwable) {
            $directUrl = null;
        }

        $file  = [
            'name' => $path,
            'size' => $service->fileSize($path),
        ];
        return [
            'file' => $file,
            'zoneId' => $zoneId,
            'directUrl' => $directUrl,
            'signed' => $signed,
        ];
    }

    #[Route('/{zoneId}/{path}', name: 'survos_storage_zone', methods: ['GET'],
        requirements: [
            'zoneId'=>'[0-9a-z_.]*',
            'path'=>'.+'
        ]
    )]
    #[Template('@SurvosStorage/zone.html.twig')]
    public function zone(
        string $zoneId,
        ?string $path='/',
        #[MapQueryParameter] bool $deep=false,
        #[MapQueryParameter] bool $addMeta=false,
        #[MapQueryParameter] bool $signed=false,
    ): Response|array
    {
        $storage = $this->storageService->getZone($zoneId);
        $this->checkSimpleDatatablesInstalled();
        $adapter = $this->storageService->getAdapter($zoneId);

//        $client = $this->storageService->getClient($adapter);
//        $bucket = $this->storageService->getBucket($adapter);

        $iterator = $storage->listContents($path, $deep);


        $files = iterator_to_array($iterator);
        $publicUrls = [];
        $signedUrls = [];
        $expiresAt = new \DateTimeImmutable('+60 minutes');

        /** @var FileAttributes $file */
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filePath = $file->path();

            try {
                $publicUrls[$filePath] = $storage->publicUrl($filePath);
            } catch (\Throwable) {
                // Not all adapters/existing ACLs support public URLs.
                $publicUrls[$filePath] = null;
            }

            if ($signed) {
                try {
                    $signedUrls[$filePath] = $storage->temporaryUrl($filePath, $expiresAt);
                } catch (\Throwable) {
                    $signedUrls[$filePath] = null;
                }
            }
        }

//        $this->addMetadata($files, $addMeta, $storage, $client, $bucket);
        $json = json_encode($files);
//        dd(count($files), strlen($json));
//        foreach ($files as $file) {
//            $storage->setVisibility($file->path(), 'public');
////            dd(get_class_methods($file));
//
//            $url = $storage->publicUrl($file->path(), $file->visibility());
//            dd($url);
//        }
//        dd(iterator_to_array($files));
//        $edgeStorageApi = $this->storageService->getEdgeApi($zoneName);
//        $list = $edgeStorageApi->listFiles(
//            storageZoneName: $zoneName,
//            path: $path
//        );
        return [
            'jsonLength' => strlen($json),
            'data' => json_decode($json, true),
            'zoneId' => $zoneId,
            'path' => $path,
            'files' => $files,
            'publicUrls' => $publicUrls,
            'signedUrls' => $signedUrls,
            'signed' => $signed,
            'deep' => $deep,
            'addMeta' => $addMeta,
        ];
    }

    /**
     * @param array $files
     * @param bool $addMeta
     * @param \League\Flysystem\Filesystem $storage
     * @param S3ClientInterface|\Psr\Http\Client\ClientInterface $client
     * @param string $bucket
     * @return void
     * @throws \League\Flysystem\FilesystemException
     */
    public function addMetadata(array $files, bool $addMeta, \League\Flysystem\Filesystem $storage, S3ClientInterface|\Psr\Http\Client\ClientInterface $client, string $bucket): void
    {
        /** @var FileAttributes $file */
        foreach ($files as $file) {
            if ($file['type'] === 'file') {
                $options = [
                    'Metadata' => [
                        'x-museado-meta-size' => $file->fileSize(),
                        'x-museado-random' => rand(0, 100000)
                    ],
                ];
                $copyFile = $file->path();
                if ($addMeta) {
                    // proper way is to do copyFile/Object if updating
                    $content = $storage->read($file->path());
                    $obj = $client->upload($bucket,
                        $copyFile,
                        body: $content,
                        options: ['params' => $options]);
                    dump(obj: $obj, data: $obj->toArray(), meta: $obj->get('@metadata'));
                }
//                $storage->delete($copyFile);
//                if (!$storage->has($copyFile)) {
//                /** @var Result $headers */
//                $headers = $client->headObject(array(
//                    "Bucket" => $bucket,
//                    "Key" => $file->path(),
//                ));
//                dump(headers: $headers,
//                    metadata: $headers->get('Metadata'),
//                    metadataKeys: $headers->get('@metadata'),
//                    file: $copyFile);

            }
        }
    }
}
