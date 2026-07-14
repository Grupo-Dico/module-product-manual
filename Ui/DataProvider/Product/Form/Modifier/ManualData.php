<?php
declare(strict_types=1);

namespace GDMexico\ProductManual\Ui\DataProvider\Product\Form\Modifier;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;

class ManualData implements ModifierInterface
{
    private const ATTRIBUTE_CODE = 'assembly_manual';

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Filesystem
     */
    private $filesystem;

    public function __construct(
        RequestInterface $request,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        Filesystem $filesystem
    ) {
        $this->request = $request;
        $this->productRepository = $productRepository;
        $this->storeManager = $storeManager;
        $this->filesystem = $filesystem;
    }

    /**
     * @param array $meta
     * @return array
     */
    public function modifyMeta(array $meta): array
    {
        return $meta;
    }

    /**
     * @param array $data
     * @return array
     */
    public function modifyData(array $data): array
    {
        $productId = (int) $this->request->getParam('id');

        if ($productId <= 0) {
            return $data;
        }

        try {
            $storeId = (int) $this->request->getParam('store', 0);

            $product = $this->productRepository->getById(
                $productId,
                false,
                $storeId
            );

            $value = $product->getData(self::ATTRIBUTE_CODE);

            if (!is_string($value) || trim($value) === '') {
                return $data;
            }

            $file = $this->normalizePath($value);

            if ($file === '') {
                return $data;
            }

            $mediaDirectory = $this->filesystem->getDirectoryRead(
                DirectoryList::MEDIA
            );

            $size = 0;

            if ($mediaDirectory->isFile($file)) {
                $stat = $mediaDirectory->stat($file);
                $size = isset($stat['size']) ? (int) $stat['size'] : 0;
            }

            $mediaUrl = rtrim(
                $this->storeManager
                    ->getStore($storeId)
                    ->getBaseUrl(UrlInterface::URL_TYPE_MEDIA),
                '/'
            );

            $uploaderValue = [
                [
                    'name' => basename($file),
                    'file' => $file,
                    'url' => $mediaUrl . '/' . ltrim($file, '/'),
                    'size' => $size,
                    'type' => 'application/pdf'
                ]
            ];

            /*
             * Magento 2.4.2 normalmente utiliza esta estructura.
             */
            if (!isset($data[$productId])) {
                $data[$productId] = [];
            }

            if (
                isset($data[$productId]['product']) &&
                is_array($data[$productId]['product'])
            ) {
                $data[$productId]['product'][self::ATTRIBUTE_CODE]
                    = $uploaderValue;
            } else {
                $data[$productId][self::ATTRIBUTE_CODE] = $uploaderValue;
            }
        } catch (\Exception $exception) {
            return $data;
        }

        return $data;
    }

    /**
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