<?php
/**
 * 2007-2015 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 *  @author 	PrestaShop SA <contact@prestashop.com>
 *  @copyright  2007-2015 PrestaShop SA
 *  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 *  International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShop\PrestaShop\Adapter\Supplier;

/**
 * This class will provide data from DB / ORM about Supplier
 */
class SupplierDataProvider
{
    /**
     * Get all suppliers
     *
     * @param bool $get_nb_products
     * @param int $id_lang
     * @param bool $active
     * @param bool $p
     * @param bool $n
     * @param bool $all_groups
     *
     * @return array Suppliers
     */
    public function getSuppliers($get_nb_products = false, $id_lang = 0, $active = true, $p = false, $n = false, $all_groups = false)
    {
        return \Supplier::getSuppliers($get_nb_products, $id_lang, $active, $p, $n, $all_groups);
    }

    /**
     * Get product suppliers
     *
     * @param int $id_product
     *
     * @return array Suppliers
     */
    public function getProductSuppliers($id_product)
    {
        $suppliersCollection = \ProductSupplier::getSupplierCollection($id_product);
        return $suppliersCollection->getResults();
    }
}