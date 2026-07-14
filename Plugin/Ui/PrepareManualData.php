<?php
declare(strict_types=1);

namespace GDMexico\ProductManual\Plugin\Ui;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

class PrepareManualData
{
    /**
     * Código del atributo.
     */
    private const ATTRIBUTE_CODE = 'assembly_manual';

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Filesystem
     */
    private $filesystem;

    public function __construct(
        StoreManagerInterface $storeManager,
        Filesystem $filesystem
    ) {
        $this->storeManager = $storeManager;
        $this->filesystem = $filesystem;
    }

    /**
     * Prepara el valor para el componente fileUploader del administrador.
     *
     * @param object $subject
     * @param array $data
     * @return array
     */
    public function afterGetData($subject, array $data): array
    {
        $mediaBaseUrl = rtrim(
            $this->storeManager
                ->getStore()
                ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA),
            '/'
        );

        $mediaDirectory = $this->filesystem->getDirectoryRead(
            DirectoryList::MEDIA
        );

        foreach ($data as &$entityData) {
            if (!is_array($entityData)) {
                continue;
            }

            /*
             * Magento normalmente coloca los atributos del producto
             * dentro del nodo "product".
             */
            if (
                isset($entityData['product']) &&
                is_array($entityData['product'])
            ) {
                $this->prepareContainer(
                    $entityData['product'],
                    $mediaBaseUrl,
                    $mediaDirectory
                );
            }

            /*
             * Compatibilidad adicional por si alguna versión entrega
             * el atributo en la raíz.
             */
            $this->prepareContainer(
                $entityData,
                $mediaBaseUrl,
                $mediaDirectory
            );
        }

        unset($entityData);

        return $data;
    }

    /**
     * @param array $container
     * @param string $mediaBaseUrl
     * @param ReadInterface $mediaDirectory
     * @return void
     */
    private function prepareContainer(
        array &$container,
        string $mediaBaseUrl,
        ReadInterface $mediaDirectory
    ): void {
        if (!array_key_exists(self::ATTRIBUTE_CODE, $container)) {
            return;
        }

        $value = $container[self::ATTRIBUTE_CODE];

        /*
         * Si Magento ya recibió el arreglo del fileUploader,
         * no debe volver a transformarse.
         */
        if (
            is_array($value) &&
            isset($value[0]) &&
            is_array($value[0])
        ) {
            return;
        }

        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $file = $this->normalizePath($value);

        if ($file === '') {
            return;
        }

        $size = 0;

        try {
            if ($mediaDirectory->isFile($file)) {
                $stat = $mediaDirectory->stat($file);
                $size = isset($stat['size']) ? (int) $stat['size'] : 0;
            }
        } catch (\Exception $exception) {
            $size = 0;
        }

        $container[self::ATTRIBUTE_CODE] = [
            [
                'name' => basename($file),
                'file' => $file,
                'url' => $mediaBaseUrl . '/' . ltrim($file, '/'),
                'size' => $size,
                'type' => 'application/pdf'
            ]
        ];
    }

    /**
     * Normaliza rutas antiguas y actuales.
     *
     * @param string $value
     * @return string
     */
    private function normalizePath(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));

        $value = preg_replace(
            '#^https?://[^/]+/(?:pub/)?media/#i',
            '',
            $value
        );

        $value = preg_replace(
            '#^/?(?:pub/)?media/#i',
            '',
            (string) $value
        );

        return ltrim((string) $value, '/');
    }
}