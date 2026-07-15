<?php
/**
 * @author GDMexico
 * @package GDMexico_ProductManual
 */

declare(strict_types=1);

namespace GDMexico\ProductManual\Plugin\Frontend;

use GDMexico\ProductManual\Setup\Patch\Data\AddAssemblyManualAttribute;
use Magento\Catalog\Block\Product\View;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;

class AppendManualToWarranty
{
    /**
     * Nombre en layout del bloque de cuidados y garantías.
     */
    private const TARGET_BLOCK = 'product.custom.warranty';

    /**
     * Clase CSS del enlace del manual.
     */
    private const MANUAL_LINK_CLASS = 'product-assembly-manual__link';

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @param Escaper $escaper
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        Escaper $escaper,
        UrlInterface $urlBuilder
    ) {
        $this->escaper = $escaper;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Cambia el título del acordeón únicamente cuando el producto
     * tiene un manual de armado.
     *
     * @param View $subject
     * @return void
     */
    public function beforeToHtml(View $subject): void
    {
        if ($subject->getNameInLayout() !== self::TARGET_BLOCK) {
            return;
        }

        $product = $subject->getProduct();

        if (!$product) {
            return;
        }

        $manualValue = trim(
            (string) $product->getData(
                AddAssemblyManualAttribute::ATTRIBUTE_CODE
            )
        );

        if ($manualValue === '') {
            return;
        }

        $subject->setData(
            'title',
            (string) __('Cuidados, garantías y manuales')
        );
    }

    /**
     * Agrega el enlace de descarga del manual al final del bloque.
     *
     * @param View $subject
     * @param string $result
     * @return string
     */
    public function afterToHtml(
        View $subject,
        string $result
    ): string {
        if ($subject->getNameInLayout() !== self::TARGET_BLOCK) {
            return $result;
        }

        /*
         * Evita agregar el manual más de una vez.
         */
        if (strpos($result, self::MANUAL_LINK_CLASS) !== false) {
            return $result;
        }

        $product = $subject->getProduct();

        if (!$product) {
            return $result;
        }

        $productId = (int) $product->getId();

        $manualValue = trim(
            (string) $product->getData(
                AddAssemblyManualAttribute::ATTRIBUTE_CODE
            )
        );

        if ($manualValue === '' || $productId <= 0) {
            return $result;
        }

        /*
         * Apunta al controller que fuerza la descarga.
         * No utiliza directamente la URL de pub/media.
         */
        $downloadUrl = $this->urlBuilder->getUrl(
            'productmanual/manual/download',
            [
                'id' => $productId,
                '_secure' => $subject->getRequest()->isSecure()
            ]
        );

        $manualHtml = sprintf(
            '<div class="product-assembly-manual info-section">'
            . '<a class="%s" href="%s" title="%s">'
            . '<span class="product-assembly-manual__icon" aria-hidden="true"></span>'
            . '<span class="product-assembly-manual__label">%s</span>'
            . '</a>'
            . '</div>',
            self::MANUAL_LINK_CLASS,
            $this->escaper->escapeUrl($downloadUrl),
            $this->escaper->escapeHtmlAttr(
                (string) __('Descargar Manual de armado')
            ),
            $this->escaper->escapeHtml(
                (string) __('Manual de armado')
            )
        );

        return $result . $manualHtml;
    }
}
