<?php
return [
    'components' => [
        'cmsExport' => [
            'handlers' => [
                'skeeks\cms\exportShopYandexMarket\ExportShopYandexMarketHandler' => [
                    'class' => 'skeeks\cms\exportShopYandexMarket\ExportShopYandexMarketHandler',
                ],
                'skeeks\cms\exportShopYandexMarket\ExportShopGoogleMerchantHandler' => [
                    'class' => 'skeeks\cms\exportShopYandexMarket\ExportShopGoogleMerchantHandler',
                ],
            ],
        ],

        'i18n' => [
            'translations' => [
                'skeeks/exportShopYandexMarket' => [
                    'class'    => 'yii\i18n\PhpMessageSource',
                    'basePath' => '@skeeks/cms/exportShopYandexMarket/messages',
                    'fileMap'  => [
                        'skeeks/exportShopYandexMarket' => 'main.php',
                    ],
                ],
            ],
        ],
    ],
];