<?php


namespace MDA\Medusa;


use Bitrix\Highloadblock\HighloadBlockTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\SectionElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Fuser;
use CModule;
use MDA\Medusa\Tables\MultiShopTable;

class MultiShop
{
    private static array $user = [];
    private static array $userData = [];
    private static array $userShop = [];
    private static array $shop = [];
    private static array $shops = [];
    private static array $shopsStocks = [];
    private static array $hl = [];
    private static array $productStocks = [];
    private static array $offerLink = [];
    private static array $available = [];

    private static function getHL($id)
    {
        if (empty(self::$hl[$id])) {
            CModule::IncludeModule("highloadblock");
            self::$hl[$id] = HighloadBlockTable::compileEntity(HighloadBlockTable::getById($id)->fetch())->getDataClass();
        }

        return self::$hl[$id];
    }

    /**
     * Формирует поля
     *
     * @param array $shop
     * @return array
     */
    private static function getShopRows(array $shop): array
    {
        return [
            'ID' => $shop['ID'],
            'ACTIVE' => $shop['UF_ACTIVE'],
            'NAME' => $shop['UF_NAME_REAL'] ?: $shop['UF_NAME'],
            'EMAIL' => $shop['UF_EMAIL'],
            'PHONE' => $shop['UF_PHONE'],
            'WAY' => $shop['UF_WAY'],
            'DESCRIPTION' => $shop['UF_DESCRIPTION'],
            'XML_ID' => $shop['UF_XML_ID'],
        ];
    }

    /**
     * Получает список магазинов
     *
     * @return array
     */
    public static function getShops(): array
    {
        if (empty(self::$shops)) {

            $hl = self::getHL(Core::getShopHlBlockId());

            $shops = $hl::getList()->fetchAll();

            foreach ($shops as $shop) {
                $shop = self::getShopRows($shop);
                if (empty($shop['ACTIVE'])) continue;
                self::$shops[$shop['XML_ID']] = $shop;
            }
        }

        return self::$shops?:[];
    }

    /**
     * Получает магазин по xml_id магазина
     *
     * @param string $shopXmlId
     * @return array
     */
    public static function getShop(string $shopXmlId): array
    {
        return self::getShops()[$shopXmlId]?:[];
    }

    /**
     *
     * Получение остатков магазина по xml_id магазина
     *
     * Fields:
     * <ul>
     * <li> ID int
     * <li> STOCK int
     * <li> PRODUCT string
     * <li> OFFER string
     * </ul>
     *
     * @param string $shopXmlId
     * @return array
     */
    public static function getShopStocks(string $shopXmlId): array
    {
        if (empty(self::$shopsStocks[$shopXmlId])) {
            $hl = self::getHL(Core::getStockHlBlockId());

            self::$shopsStocks[$shopXmlId] = $hl::getList([
                'filter' => [
                    '>UF_KOLICHESTVO' => 0,
                    'UF_MAGAZIN' => $shopXmlId,
                ],
                'select' => [
                    'ID',
                    'UF_KOLICHESTVO',
                    'UF_NOMENKLATURA',
                    'UF_NAME',
                ]
            ])->fetchAll();

            foreach (self::$shopsStocks[$shopXmlId] as &$stock) {

                $explode = explode('#', $stock['UF_NAME']);
                $product = $explode[0];
                $offer = $explode[1];

                $stock = [
                    'ID' => (int) $stock['ID'],
                    'STOCK' => (int) $stock['UF_KOLICHESTVO'],
                    'PRODUCT' => $product?: $stock['UF_NOMENKLATURA'],
                    'OFFER' => $offer,
                ];
            }
        }

        return self::$shopsStocks[$shopXmlId]?:[];
    }

    /**
     * Получает информацию о пользователе bitrix
     *
     * @return array
     */
    private static function getUser(): array
    {
        if (empty(self::$user)) {
            CModule::IncludeModule("sale");
            self::$user['FUSER_ID'] = Fuser::getId();
            self::$user['USER_ID'] = CurrentUser::get()->getId();
        }
        return self::$user;
    }

    /**
     * Удаляет старые записи пользователей
     * Время жизни записи устанавливается в настройках модуля
     */
    private static function removeOldUserData()
    {
        $list = MultiShopTable::getList([
            'filter' => [
                '<DATE_CREATE' => (new DateTime())->add(Core::getUserDataLifetime() * -1 . ' min'),
            ],
            'select' => ['ID'],
        ]);

        while ($item = $list->fetch()) {
            MultiShopTable::delete($item['ID']);
        }
    }

    /**
     * Получает данные пользователя
     *
     * @return array
     */
    public static function getUserData(): array
    {
        if (empty(self::$userData)) {
            $user = self::getUser();

            if (!empty($user['USER_ID'])) {
                $filter = ['USER_ID' => $user['USER_ID']];
            } else {
                $filter = ['FUSER_ID' => $user['FUSER_ID']];
            }

            $userData = MultiShopTable::getRow([
                'filter' => $filter,
                'select' => [
                    'ID',
                    'FUSER_ID',
                    'USER_ID',
                    'XML_ID',
                    'AUTO_SELECT',
                ],
            ]);

            self::$userData = $userData?:[];
        }

        return self::$userData;
    }

    /**
     * Получает магазин пользователя
     *
     * @return array
     */
    public static function getUserShop(): array
    {
        if (empty(self::$userShop)) {
            if ($session = self::getSession()) {
                self::$userShop = $session;
            } else if ($userData = self::getUserData()) {
                self::$userShop = array_merge($userData, self::getShop($userData['XML_ID']));
            } else {
                return [];
            }
        }

        return self::$userShop?:[];
    }

    /**
     * Устанавливает магазин для пользователя
     *
     * @param string $shopXmlId - xml_id магазина
     * @param bool $autoSelect - было ли установлено автоматически
     * @return bool
     */
    public static function setUserShop(string $shopXmlId, bool $autoSelect = true): bool
    {
        $fields = [
            'XML_ID' => $shopXmlId,
            'AUTO_SELECT' => $autoSelect,
        ];

        if ($id = self::getUserData()['ID']) {
            $result = MultiShopTable::update($id, $fields);
            if (!$result->isSuccess()) return false;
            self::$userData = array_merge(self::$userData, $fields);
        } else {
            $user = self::getUser();
            $fields = array_merge($fields, [
                'FUSER_ID' => $user['FUSER_ID'],
                'USER_ID' => $user['USER_ID'],
            ]);
            $result = MultiShopTable::add($fields);
            if (!$result->isSuccess()) return false;
            self::$userData = array_merge(self::$userData, $fields);
        }

        self::setSession(array_merge(self::$userData, self::getShop($shopXmlId)));

        return true;
    }

    /**
     * Добавляет пользователя если нет и устанавливает город по умолчанию
     *
     * @return bool
     */
    public static function addUser(): bool
    {
        self::removeOldUserData();

        if (self::$userData = MultiShop::getUserData()) {
            self::setSession(array_merge(self::$userData, self::getShop(self::$userData['XML_ID'])));
            return true;
        }

        if ($session = self::getSession()) {
            $shopXmlId = $session['XML_ID'];
        } else {
            $shops = MultiShop::getShops();
            $shopXmlId = $shops[array_key_first($shops)]['XML_ID'];
        }

        return MultiShop::setUserShop($shopXmlId);
    }

    /**
     * Получает фильтры для каталога по xml_id магазина
     *
     * @param string $shopXmlId
     * @return array|array[]
     */
    public static function getFiltersByShop(string $shopXmlId): array
    {
        $products = [];

        $stocks = self::getShopStocks($shopXmlId);
        foreach ($stocks as $stock) {
            $products[] = $stock['PRODUCT'];
        }

        if (empty($products)) return [];

        $elements = ElementTable::getList([
            'filter' => [
                'XML_ID' => $products,
                'IBLOCK_ID' => Core::getProductIblockId(),
            ],
            'select' => [
                'ID',
                'IBLOCK_SECTION_ID',
                'IBLOCK_ADDITIONAL_SECTION_ID' => 'SECTION.IBLOCK_SECTION_ID',
            ],
            'runtime' => [
                'SECTION' => [
                    'data_type' => SectionElementTable::class,
                    'reference' => [
                        '=this.ID' => 'ref.IBLOCK_ELEMENT_ID',
                    ],
                    'join_type' => 'left'
                ],
            ],
        ]);

        while ($element = $elements->fetch()) {
            $productIds[] = $element['ID'];
            $sectionIds[] = $element['IBLOCK_SECTION_ID'];
            $sectionIds[] = $element['IBLOCK_ADDITIONAL_SECTION_ID'];
        }

        $sections = SectionTable::getList([
            'filter' => [
                'IBLOCK_ID' => Core::getProductIblockId(),
                'ID' => array_unique($sectionIds),
            ],
            'select' => [
                'SECTION_ID' => 'SECTION.ID',
            ],
            'runtime' => [
                'SECTION' => [
                    'data_type' => SectionTable::class,
                    'reference' => [
                        '=this.IBLOCK_ID' => 'ref.IBLOCK_ID',
                        '>=this.LEFT_MARGIN' => 'ref.LEFT_MARGIN',
                        '<=this.RIGHT_MARGIN' => 'ref.RIGHT_MARGIN',
                    ],
                    'join_type' => 'inner'
                ],
            ],
        ])->fetchAll();

        $sectionIds = array_map(function ($section) { return $section['SECTION_ID']; },$sections);

        return [
            'FILTER_PRODUCTS' => [
                'ID' => array_unique($productIds),
            ],
            'FILTER_SECTIONS' => [
                'ID' => array_unique($sectionIds),
            ],
        ];
    }

    /**
     * Получает остатки товара по xml_id товара
     *
     * @param string $xmlId
     * @return int
     */
    public static function getProductStocks(string $xmlId): int
    {
        $userShop = self::getUserShop();

        if (empty(self::$productStocks)) {
            $stocks = self::getShopStocks($userShop['XML_ID']);
            foreach ($stocks as $stock) {
                if ($stock['OFFER']) {
                    $productXmlId = implode('#', [$stock['PRODUCT'],$stock['OFFER']]);
                } else {
                    $productXmlId = $stock['PRODUCT'];
                }

                self::$productStocks[$userShop['XML_ID']][$productXmlId] = $stock['STOCK'];
            }
        }

        return self::$productStocks[$userShop['XML_ID']][$xmlId]?:0;
    }

    /**
     * Проверяет доступность товара
     *
     * @param string $xmlId
     * @return bool
     */
    public static function isAvailable(string $xmlId): bool
    {
        if (!self::$available[$xmlId]) {
            self::$available[$xmlId] = (bool) self::getProductStocks($xmlId) > 0;
        }

        return self::$available[$xmlId];
    }

    /**
     * Получает торговые предложения магазина
     *
     * @return array
     */
    public static function getOffers()
    {
        $result = [];
        $userShop = self::getUserShop();
        $stocks = self::getShopStocks($userShop['XML_ID']);
        foreach ($stocks as $stock) {
            if (!$stock['OFFER']) continue;
            $result[] = implode('#', [$stock['PRODUCT'],$stock['OFFER']]);
        }

        return $result;
    }

    public static function getSession(): array
    {
        $session = Application::getInstance()->getSession();
        return json_decode($session[self::class],true)?:[];
    }

    public static function setSession($data)
    {
        $session = Application::getInstance()->getSession();
        $session->set(self::class, json_encode($data));
    }
}