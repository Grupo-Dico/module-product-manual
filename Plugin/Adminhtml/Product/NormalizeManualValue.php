<?php
declare(strict_types=1);

namespace GDMexico\ProductManual\Plugin\Adminhtml\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Controller\Adminhtml\Product\Save;
use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

class NormalizeManualValue
{
    private const ATTRIBUTE_CODE = 'assembly_manual';
    private const DELETE_FIELD = 'assembly_manual_delete';
    private const ORIGINAL_FIELD = 'assembly_manual_original';
    private const ALLOWED_DIRECTORY = 'product/manuals/';

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ProductAction
     */
    private $productAction;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var EavConfig
     */
    private $eavConfig;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        RequestInterface $request,
        ProductRepositoryInterface $productRepository,
        ProductAction $productAction,
        ResourceConnection $resourceConnection,
        EavConfig $eavConfig,
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->productRepository = $productRepository;
        $this->productAction = $productAction;
        $this->resourceConnection = $resourceConnection;
        $this->eavConfig = $eavConfig;
        $this->filesystem = $filesystem;
        $this->logger = $logger;
    }

    /**
     * Guarda o elimina el manual del producto.
     *
     * @param Save $subject
     * @param callable $proceed
     * @return ResultInterface
     */
    public function aroundExecute(
        Save $subject,
        callable $proceed
    ): ResultInterface {
        $allParams = $this->request->getParams();
        $allParams = is_array($allParams) ? $allParams : [];

        $productData = $this->request->getParam('product');
        $productData = is_array($productData) ? $productData : [];

        $storeId = (int) $this->request->getParam('store', 0);
        $productId = $this->resolveProductId($productData);

        /*
         * Comprueba si assembly_manual llegó en la petición.
         *
         * En Magento 2.4.2 puede no llegar cuando se elimina el archivo.
         */
        $manualWasSubmitted = array_key_exists(
            self::ATTRIBUTE_CODE,
            $productData
        );

        $newManualValue = $manualWasSubmitted
            ? $this->normalizeManualValue(
                $productData[self::ATTRIBUTE_CODE]
            )
            : '';

        /*
         * Recupera la ruta original enviada por el campo oculto.
         */
        $originalFile = $this->findOriginalFile(
            $productData,
            $allParams
        );

        /*
         * Si el campo oculto no llegó, recupera la ruta directamente
         * desde la base de datos.
         */
        if ($originalFile === '' && $productId > 0) {
            $originalFile = $this->getStoredValue(
                $productId,
                $storeId
            );
        }

        $explicitDelete = $this->isDeleteRequested($productData)
            || $this->isDeleteRequested($allParams);

        /*
         * Detecta que el archivo fue removido.
         *
         * Estado normal:
         * originalFile    = product/manuals/x/y/manual.pdf
         * newManualValue  = product/manuals/x/y/manual.pdf
         *
         * Estado después de eliminar:
         * originalFile    = product/manuals/x/y/manual.pdf
         * newManualValue  = ''
         *
         * No dependemos de $manualWasSubmitted porque Magento 2.4.2
         * puede omitir assembly_manual cuando el uploader queda vacío.
         */
        $manualRemoved = $originalFile !== ''
            && $newManualValue === '';

        $deleteRequested = $explicitDelete || $manualRemoved;

        $this->logger->info(
            '[ProductManual] Estado recibido al guardar.',
            [
                'product_id' => $productId,
                'store_id' => $storeId,
                'manual_was_submitted' => $manualWasSubmitted,
                'new_manual_value' => $newManualValue,
                'original_file' => $originalFile,
                'explicit_delete' => $explicitDelete,
                'manual_removed' => $manualRemoved,
                'delete_requested' => $deleteRequested,
            ]
        );

        /*
         * El borrado tiene prioridad.
         */
        if ($deleteRequested) {
            /*
             * Evita que Magento vuelva a guardar la ruta anterior.
             */
            $productData[self::ATTRIBUTE_CODE] = '';
            $productData[self::DELETE_FIELD] = 1;
            $productData[self::ORIGINAL_FIELD] = $originalFile;

            $this->request->setParam(
                'product',
                $productData
            );

            /*
             * Guarda normalmente el resto del producto.
             */
            $result = $proceed();

            /*
             * Intenta resolver nuevamente el ID después del guardado.
             */
            if ($productId <= 0) {
                $productId = $this->resolveProductId(
                    $productData
                );
            }

            if ($productId <= 0) {
                $this->logger->error(
                    '[ProductManual] No se pudo resolver el producto para eliminar el manual.',
                    [
                        'sku' => isset($productData['sku'])
                            ? (string) $productData['sku']
                            : '',
                    ]
                );

                return $result;
            }

            try {
                /*
                 * Elimina todas las asociaciones EAV del manual.
                 */
                $deletedRows = $this->deleteAttributeRows(
                    $productId
                );

                /*
                 * Elimina físicamente el PDF cuando ya no está
                 * referenciado por ningún producto.
                 */
                $physicalFileDeleted =
                    $this->deletePhysicalFileIfUnused(
                        $originalFile
                    );

                $this->logger->info(
                    '[ProductManual] Manual eliminado.',
                    [
                        'product_id' => $productId,
                        'store_id' => $storeId,
                        'file' => $originalFile,
                        'deleted_rows' => $deletedRows,
                        'physical_file_deleted' =>
                            $physicalFileDeleted,
                    ]
                );
            } catch (\Throwable $exception) {
                $this->logger->critical(
                    '[ProductManual] Error eliminando el manual.',
                    [
                        'product_id' => $productId,
                        'store_id' => $storeId,
                        'file' => $originalFile,
                        'message' => $exception->getMessage(),
                    ]
                );
            }

            return $result;
        }

        /*
         * Si hay un archivo nuevo, normaliza el valor antes de ejecutar
         * el guardado nativo de Magento.
         */
        if ($newManualValue !== '') {
            $productData[self::ATTRIBUTE_CODE] =
                $newManualValue;

            $productData[self::DELETE_FIELD] = 0;
            $productData[self::ORIGINAL_FIELD] = '';

            $this->request->setParam(
                'product',
                $productData
            );
        }

        /*
         * Magento guarda el resto del producto.
         */
        $result = $proceed();

        /*
         * Si no se cargó un archivo nuevo, no hay nada que persistir.
         */
        if ($newManualValue === '') {
            return $result;
        }

        if ($productId <= 0) {
            $productId = $this->resolveProductId(
                $productData
            );
        }

        if ($productId <= 0) {
            $this->logger->error(
                '[ProductManual] No se pudo resolver el producto para guardar el manual.',
                [
                    'sku' => isset($productData['sku'])
                        ? (string) $productData['sku']
                        : '',
                ]
            );

            return $result;
        }

        try {
            /*
             * Persistencia explícita porque Magento puede ignorar
             * el valor del fileUploader en el guardado estándar.
             */
            $this->productAction->updateAttributes(
                [$productId],
                [
                    self::ATTRIBUTE_CODE =>
                        $newManualValue,
                ],
                $storeId
            );

            $this->logger->info(
                '[ProductManual] Manual guardado explícitamente.',
                [
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'value' => $newManualValue,
                ]
            );
        } catch (\Throwable $exception) {
            $this->logger->critical(
                '[ProductManual] Error guardando el manual.',
                [
                    'product_id' => $productId,
                    'store_id' => $storeId,
                    'value' => $newManualValue,
                    'message' => $exception->getMessage(),
                ]
            );
        }

        return $result;
    }

    /**
     * Convierte la respuesta del fileUploader en una ruta.
     *
     * @param mixed $value
     * @return string
     */
    private function normalizeManualValue($value): string
    {
        if (is_string($value)) {
            return $this->sanitizePath($value);
        }

        if (!is_array($value) || $value === []) {
            return '';
        }

        /*
         * Formato normal:
         *
         * [
         *     [
         *         'name' => 'manual.pdf',
         *         'file' => 'product/manuals/m/a/manual.pdf',
         *         'url' => '...'
         *     ]
         * ]
         */
        if (
            isset($value[0])
            && is_array($value[0])
        ) {
            $value = $value[0];
        }

        /*
         * Compatibilidad con respuestas que contienen files.
         */
        if (
            isset($value['files'][0])
            && is_array($value['files'][0])
        ) {
            $value = $value['files'][0];
        }

        if (!empty($value['file'])) {
            return $this->sanitizePath(
                (string) $value['file']
            );
        }

        if (!empty($value['url'])) {
            return $this->sanitizePath(
                (string) $value['url']
            );
        }

        return '';
    }

    /**
     * Resuelve el ID del producto.
     *
     * @param array $productData
     * @return int
     */
    private function resolveProductId(
        array $productData
    ): int {
        $productId = (int) $this->request
            ->getParam('id');

        if ($productId > 0) {
            return $productId;
        }

        $sku = trim(
            (string) ($productData['sku'] ?? '')
        );

        if ($sku === '') {
            return 0;
        }

        try {
            return (int) $this->productRepository
                ->get($sku, false, null, true)
                ->getId();
        } catch (\Throwable $exception) {
            return 0;
        }
    }

    /**
     * Elimina todas las filas EAV del atributo para el producto.
     *
     * @param int $productId
     * @return int
     */
    private function deleteAttributeRows(
        int $productId
    ): int {
        $attribute = $this->eavConfig->getAttribute(
            'catalog_product',
            self::ATTRIBUTE_CODE
        );

        $attributeId = (int) $attribute
            ->getAttributeId();

        if ($attributeId <= 0) {
            throw new \RuntimeException(
                'No existe el atributo assembly_manual.'
            );
        }

        $connection = $this->resourceConnection
            ->getConnection();

        return $connection->delete(
            $attribute->getBackendTable(),
            [
                'attribute_id = ?' => $attributeId,
                'entity_id = ?' => $productId,
            ]
        );
    }

    /**
     * Obtiene el valor actualmente almacenado en EAV.
     *
     * @param int $productId
     * @param int $storeId
     * @return string
     */
    private function getStoredValue(
        int $productId,
        int $storeId
    ): string {
        $attribute = $this->eavConfig->getAttribute(
            'catalog_product',
            self::ATTRIBUTE_CODE
        );

        $attributeId = (int) $attribute
            ->getAttributeId();

        if ($attributeId <= 0) {
            return '';
        }

        $connection = $this->resourceConnection
            ->getConnection();

        $storeIds = $storeId === 0
            ? [0]
            : [$storeId, 0];

        $select = $connection->select()
            ->from(
                $attribute->getBackendTable(),
                ['value']
            )
            ->where('attribute_id = ?', $attributeId)
            ->where('entity_id = ?', $productId)
            ->where('store_id IN (?)', $storeIds)
            ->order('store_id DESC')
            ->limit(1);

        return trim(
            (string) $connection->fetchOne($select)
        );
    }

    /**
     * Elimina el archivo físico si ya no tiene referencias.
     *
     * @param string $value
     * @return bool
     */
    private function deletePhysicalFileIfUnused(
        string $value
    ): bool {
        $file = $this->sanitizePath($value);

        /*
         * Seguridad: solo permite eliminar dentro de product/manuals.
         */
        if (
            $file === ''
            || strpos(
                $file,
                self::ALLOWED_DIRECTORY
            ) !== 0
        ) {
            return false;
        }

        /*
         * Si otro producto usa el archivo, no lo elimina físicamente.
         */
        if ($this->countReferences($file) > 0) {
            return false;
        }

        $mediaDirectory = $this->filesystem
            ->getDirectoryWrite(
                DirectoryList::MEDIA
            );

        if (!$mediaDirectory->isExist($file)) {
            return false;
        }

        $mediaDirectory->delete($file);

        return true;
    }

    /**
     * Cuenta cuántas asociaciones tiene el archivo.
     *
     * @param string $file
     * @return int
     */
    private function countReferences(
        string $file
    ): int {
        $attribute = $this->eavConfig->getAttribute(
            'catalog_product',
            self::ATTRIBUTE_CODE
        );

        $attributeId = (int) $attribute
            ->getAttributeId();

        if ($attributeId <= 0) {
            return 0;
        }

        $connection = $this->resourceConnection
            ->getConnection();

        $select = $connection->select()
            ->from(
                $attribute->getBackendTable(),
                [
                    'total' => new \Zend_Db_Expr(
                        'COUNT(*)'
                    ),
                ]
            )
            ->where('attribute_id = ?', $attributeId)
            ->where('value = ?', $file);

        return (int) $connection
            ->fetchOne($select);
    }

    /**
     * Obtiene la ruta original enviada por el formulario.
     *
     * @param array $productData
     * @param array $allParams
     * @return string
     */
    private function findOriginalFile(
        array $productData,
        array $allParams
    ): string {
        $value = $this->findRecursive(
            $productData,
            self::ORIGINAL_FIELD
        );

        if ($value === null) {
            $value = $this->findRecursive(
                $allParams,
                self::ORIGINAL_FIELD
            );
        }

        return $this->sanitizePath(
            is_scalar($value)
                ? (string) $value
                : ''
        );
    }

    /**
     * Revisa la bandera explícita de eliminación.
     *
     * @param array $data
     * @return bool
     */
    private function isDeleteRequested(
        array $data
    ): bool {
        $value = $this->findRecursive(
            $data,
            self::DELETE_FIELD
        );

        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 'true'
            || $value === 'yes';
    }

    /**
     * Busca una clave recursivamente.
     *
     * @param array $data
     * @param string $key
     * @return mixed|null
     */
    private function findRecursive(
        array $data,
        string $key
    ) {
        if (array_key_exists($key, $data)) {
            return $data[$key];
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }

            $found = $this->findRecursive(
                $value,
                $key
            );

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Normaliza URLs y rutas de media.
     *
     * @param string $value
     * @return string
     */
    private function sanitizePath(
        string $value
    ): string {
        $value = trim(
            str_replace('\\', '/', $value)
        );

        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value)) {
            $path = parse_url(
                $value,
                PHP_URL_PATH
            );

            $value = is_string($path)
                ? $path
                : '';
        }

        $value = (string) preg_replace(
            '#^.*?/(?:pub/)?media/#i',
            '',
            $value
        );

        $value = (string) preg_replace(
            '#^/?(?:pub/)?media/#i',
            '',
            $value
        );

        return ltrim($value, '/');
    }
}