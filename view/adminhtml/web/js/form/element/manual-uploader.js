define([
    'Magento_Ui/js/form/element/file-uploader',
    'jquery'
], function (FileUploader, $) {
    'use strict';

    return FileUploader.extend({
        defaults: {
            deleteFlagPath: 'data.product.assembly_manual_delete',
            originalFilePath: 'data.product.assembly_manual_original'
        },

        /**
         * Inicializa el uploader y detecta cuando aparece un archivo nuevo.
         *
         * @returns {Object}
         */
        initialize: function () {
            this._super();

            this.setDeleteFlag(0);

            if (
                this.value
                && typeof this.value.subscribe === 'function'
            ) {
                this.value.subscribe(function (value) {
                    /*
                     * Cuando existe un archivo nuevo en el uploader,
                     * se cancela cualquier solicitud de eliminación anterior.
                     */
                    if (
                        Array.isArray(value)
                        && value.length > 0
                        && value[0]
                    ) {
                        this.setDeleteFlag(0);
                        this.clearOriginalFile();
                    }
                }, this);
            }

            return this;
        },

        /**
         * Se ejecuta cuando se elimina el archivo desde el uploader.
         *
         * @param {Object} file
         * @returns {*}
         */
        removeFile: function (file) {
            var originalFile = this.getOriginalFile(file);

            this.setDeleteFlag(1);
            this.setOriginalFile(originalFile);

            return this._super(file);
        },

        /**
         * Actualiza la bandera en el data source y en un input HTML real.
         *
         * @param {Number} value
         */
        setDeleteFlag: function (value) {
            var $form = this.getProductForm(),
                $input;

            if (
                this.source
                && typeof this.source.set === 'function'
            ) {
                this.source.set(
                    this.deleteFlagPath,
                    value
                );

                this.source.set(
                    'data.assembly_manual_delete',
                    value
                );
            }

            if (!$form.length) {
                return;
            }

            $input = $form.find(
                'input[name="product[assembly_manual_delete]"]'
            );

            if (!$input.length) {
                $input = $('<input/>', {
                    type: 'hidden',
                    name: 'product[assembly_manual_delete]'
                }).appendTo($form);
            }

            $input.val(value);
        },

        /**
         * Guarda la ruta original para eliminar físicamente el PDF.
         *
         * @param {String} value
         */
        setOriginalFile: function (value) {
            var $form = this.getProductForm(),
                $input;

            value = value || '';

            if (
                this.source
                && typeof this.source.set === 'function'
            ) {
                this.source.set(
                    this.originalFilePath,
                    value
                );

                this.source.set(
                    'data.assembly_manual_original',
                    value
                );
            }

            if (!$form.length) {
                return;
            }

            $input = $form.find(
                'input[name="product[assembly_manual_original]"]'
            );

            if (!$input.length) {
                $input = $('<input/>', {
                    type: 'hidden',
                    name: 'product[assembly_manual_original]'
                }).appendTo($form);
            }

            $input.val(value);
        },

        /**
         * Limpia la ruta anterior cuando se carga un PDF nuevo.
         */
        clearOriginalFile: function () {
            var $form = this.getProductForm(),
                $input;

            if (
                this.source
                && typeof this.source.set === 'function'
            ) {
                this.source.set(
                    this.originalFilePath,
                    ''
                );

                this.source.set(
                    'data.assembly_manual_original',
                    ''
                );
            }

            if (!$form.length) {
                return;
            }

            $input = $form.find(
                'input[name="product[assembly_manual_original]"]'
            );

            if ($input.length) {
                $input.val('');
            }
        },

        /**
         * Obtiene la ruta real del archivo mostrado.
         *
         * @param {Object} file
         * @returns {String}
         */
        getOriginalFile: function (file) {
            var currentValue;

            file = file || {};

            if (file.file) {
                return file.file;
            }

            if (file.url) {
                return file.url;
            }

            if (
                this.value
                && typeof this.value === 'function'
            ) {
                currentValue = this.value();

                if (
                    Array.isArray(currentValue)
                    && currentValue.length
                    && currentValue[0]
                ) {
                    return currentValue[0].file
                        || currentValue[0].url
                        || '';
                }
            }

            return '';
        },

        /**
         * Localiza el formulario de edición del producto.
         *
         * @returns {jQuery}
         */
        getProductForm: function () {
            var $form = $('#product-edit-form');

            if (!$form.length) {
                $form = $('form[data-form="edit-product"]');
            }

            if (!$form.length) {
                $form = $('form')
                    .has('[name="product[sku]"]')
                    .first();
            }

            return $form;
        }
    });
});