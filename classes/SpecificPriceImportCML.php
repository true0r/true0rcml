<?php

class SpecificPriceImportCML extends ImportCML
{
    public $idEntityCMLName = array('Ид', 'ИдТипаЦены');
    public $cache = false;

    public $groupPrice;
    public $defaultGroupPrice;

    public function __construct()
    {
        $this->groupPrice = array(
            '1cdcd377-d969-11e5-80b4-305a3a0c66cb' => 4, // Интернет цена USD
            'b3ffdefa-ce67-11e5-b16b-848f69ca8cb1' => 4, // Интернет цена
            '1cdcd376-d969-11e5-80b4-305a3a0c66cb' => 5, // Оптовая цена USD
            '6171e818-ce66-11e5-b16b-848f69ca8cb1' => 5, // Оптовая цена
        );
        $this->defaultGroupPrice = 4;

        parent::__construct();
    }

    public function getDefaultFields()
    {
        return array_merge(
            parent::getDefaultFields(),
            array(
                'id_shop' => 0,
                'id_shop_group' => 0,
                'id_currency' => 0,
                'id_country' => 0,
                'id_customer' => 0,
                'id_product_attribute' => 0,
                'from_quantity' => 1,
                'reduction' => 0,
                'reduction_type' => 'amount',
                'reduction_tax' => 1,
                'from' => '0000-00-00 00:00:00',
                'to' => '0000-00-00 00:00:00',
            )
        );
    }
    public function getCalcFields()
    {
        $fields = array();
        if (!isset($this->xml->Цены)) {
            $price = (float) $this->xml->ЦенаЗаЕдиницу;
            $currencyISO = (string) $this->xml->Валюта;
            if ($idCurrency = Currency::getIdByIsoCode($currencyISO)) {
                // ??? currency->active
                /** @var Currency $currency */
                $currency = Currency::getCurrency($idCurrency);
                $fields['price'] = Tools::math_round(Tools::convertPrice($price, $currency, false), 6);
            } else {
                throw new ImportCMLException("Цены не могут быть импортированы, так как валюта ($currencyISO) ".
                    "не существут в магазине, возможно указан неверный ISO код");
            }

            $fields['id_group'] = $this->groupPrice[(string) $this->xml->ИдТипаЦены];
        }
        return array_merge(parent::getCalcFields(), $fields);
    }

    public function setHash()
    {
        if (!isset($this->xml->Цены)) {
            parent::setHash();
        }
    }

    public function setEntity()
    {
        static $guidProduct;

        if (isset($this->xml->Цены)) {
            $guidProduct = $this->idEntityCML;
        } else {
            $this->idEntityCML = md5($guidProduct.$this->idEntityCML);
            parent::setEntity();
        }
    }

    public function save()
    {
        static $productUpd = false;

        if (isset($this->xml->Цены)) {
            if (!$idProduct = EntityCML::getIdTarget($this->idEntityCML)) {
                self::setWarning('Не могу выполнить импорт некоторых цен, так как номенклатура отсутствует на сайте');
                return false;
            }
            // todo управление складами
            // Обработка количества товаров
            $quantity = isset($this->xml->Количество) ? (int) $this->xml->Количество : 0;
            $sa = StockAvailable::getQuantityAvailableByProduct($idProduct);
            if ($quantity != $sa) {
                StockAvailable::setQuantity($idProduct, 0, $quantity);
                // При количествах одновременно равных 0 или больше 0, не происходит изменение поля Product::active
                if (!(0 < $quantity && 0 < $sa)) {
                    $productActive = Db::getInstance()->getValue(
                        (new DbQuery())
                            ->select('active')
                            ->from(Product::$definition['table'])
                            ->where(Product::$definition['primary']." = ".$idProduct)
                    );
                    if ((0 == $quantity && $productActive) || (0 < $quantity && !$productActive)) {
                        $product = new Product($idProduct);
                        $productActive = (int) !$productActive;
                        $product->active = $productActive;
                        $product->setFieldsToUpdate(array('active' => true));
                        $product->update();
                        Hook::exec(
                            'actionProductActivation',
                            array(
                                'id_product' => (int)$product->id,
                                'product' => $product,
                                'activated' => $productActive
                            )
                        );
                    }
                }

                $productUpd = true;
            };

            return self::walkChildren($this->xml->Цены, array('id_product' => $idProduct));
        } else {
            // retro
            if (!Validate::isLoadedObject($this->entity)) {
                DB::getInstance()->delete(
                    SpecificPrice::$definition['table'],
                    "id_product = {$this->fields['id_product']} AND id_group = {$this->fields['id_group']}
                    AND id_shop = 0 AND id_country = 0 AND id_currency = 0"
                );
            }

            if (parent::save()) {
                if ($this->fields['id_group'] == $this->defaultGroupPrice) {
                    $idProduct = $this->fields['id_product'];
                    $price = $this->fields['price'];

                    // OPT Нет необходимости в проверки изминений
                    $priceChange = !DB::getInstance()->getValue(
                        (new DbQuery())
                            ->select('1')
                            ->from(Product::$definition['table'])
                            ->where(Product::$definition['primary']." = $idProduct")
                            ->where("price = $price")
                    );

                    if ($priceChange) {
                        $db = Db::getInstance();
                        $table = Product::$definition['table'];
                        $db->update($table, array('price' => $price), "id_product = $idProduct");
                        $db->update($table.'_shop', array('price' => $price), "id_product = $idProduct");

                        $productUpd = true;
                    }

                    Hook::exec(
                        'actionAddPriceCML',
                        array(
                            'id_product' => $idProduct,
                            'id_currency' => Currency::getIdByIsoCode((string) $this->xml->Валюта)
                        )
                    );
                }

                if ($productUpd) {
                    // for statistic
                    ImportCML::getInstance('Товар')->countUpd++;
                    $productUpd = false;
                }

                return true;
            } else {
                return false;
            }
        }
    }
}
