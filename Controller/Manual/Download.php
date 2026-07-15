<?php
declare(strict_types=1);

namespace GDMexico\ProductManual\Controller\Manual;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Store\Model\StoreManagerInterface;

class Download extends Action
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var FileFactory
     */
    private $fileFactory;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        Context $context,
        ProductRepositoryInterface $productRepository,
        FileFactory $fileFactory,
        Filesystem $filesystem,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);

        $this->productRepository = $productRepository;
        $this->fileFactory = $fileFactory;
        $this->filesystem = $filesystem;
        $this->storeManager = $storeManager;
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|Redirect
     */
    public function execute()
    {
        try {
            $productId = (int) $this->getRequest()->getParam('id');

            if ($productId <= 0) {
                throw new LocalizedException(
                    __('Invalid product.')
                );
            }

            $storeId = (int) $this->storeManager
                ->getStore()
                ->getId();

            $product = $this->productRepository->getById(
                $productId,
                false,
                $storeId
            );

            $manualValue = trim(
                (string) $product->getData('assembly_manual')
            );

            if ($manualValue === '') {
                throw new LocalizedException(
                    __('The product does not have an assembly manual.')
                );
            }

            $filePath = $this->normalizePath($manualValue);

            if ($filePath === '') {
                throw new LocalizedException(
                    __('The assembly manual path is invalid.')
                );
            }

            $mediaDirectory = $this->filesystem->getDirectoryRead(
                DirectoryList::MEDIA
            );

            if (!$mediaDirectory->isFile($filePath)) {
                throw new LocalizedException(
                    __('The assembly manual file does not exist.')
                );
            }

            return $this->fileFactory->create(
                basename($filePath),
                [
                    'type' => 'filename',
                    'value' => $filePath
                ],
                DirectoryList::MEDIA,
                'application/pdf'
            );
        } catch (\Exception $exception) {
            $this->messageManager->addErrorMessage(
                $exception->getMessage()
            );

            return $this->resultRedirectFactory
                ->create()
                ->setPath('');
        }
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
