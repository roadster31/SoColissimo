<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/

namespace SoColissimo;

use Thelia\Model\ConfigQuery;
use Thelia\Model\Country;
use Thelia\Model\ModuleQuery;
use Thelia\Module\BaseModule;
use Thelia\Module\DeliveryModuleInterface;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Exception\OrderException;
use Propel\Runtime\Connection\ConnectionInterface;
use Thelia\Install\Database;
use SoColissimo\Model\SocolissimoFreeshippingQuery;

class SoColissimo extends BaseModule implements DeliveryModuleInterface
{
    protected $request;
    protected $dispatcher;

    private static $prices = null;

    const JSON_PRICE_RESOURCE = "/Config/prices.json";
    const JSON_CONFIG_PATH = "/Config/config.json";

    public static function getPrices()
    {
        if (null === self::$prices) {
            self::$prices = json_decode(file_get_contents(sprintf('%s%s', __DIR__, self::JSON_PRICE_RESOURCE)), true);
        }

        return self::$prices;
    }

    /**
     * @param $areaId
     * @param $weight
     *
     * @return mixed
     * @throws \Thelia\Exception\OrderException
     */
    public static function getPostageAmount($areaId, $weight)
    {
        $freeshipping = SocolissimoFreeshippingQuery::create()->getLast();
        $postage=0;
        if (!$freeshipping) {
            $prices = self::getPrices();

            /* check if Colissimo delivers the asked area */
            if (!isset($prices[$areaId]) || !isset($prices[$areaId]["slices"])) {
                throw new OrderException("SoColissimo delivery unavailable for the chosen delivery country", OrderException::DELIVERY_MODULE_UNAVAILABLE);
            }

            $areaPrices = $prices[$areaId]["slices"];
            ksort($areaPrices);

            /* check this weight is not too much */
            end($areaPrices);
            $maxWeight = key($areaPrices);
            if ($weight > $maxWeight) {
                throw new OrderException(sprintf("SoColissimo delivery unavailable for this cart weight (%s kg)", $weight), OrderException::DELIVERY_MODULE_UNAVAILABLE);
            }

            $postage = current($areaPrices);

            while (prev($areaPrices)) {
                if ($weight > key($areaPrices)) {
                    break;
                }

                $postage = current($areaPrices);
            }
        }

        return $postage;

    }

    /**
     *
     * calculate and return delivery price
     *
     * @param  Country                          $country
     * @return mixed
     * @throws \Thelia\Exception\OrderException
     */
    public function getPostage(Country $country)
    {
        $cartWeight = $this->getContainer()->get('request')->getSession()->getCart()->getWeight();

        $postage = self::getPostageAmount(
            $country->getAreaId(),
            $cartWeight
        );

        return $postage;
    }

    public function getCode()
    {
        return 'SoColissimo';
    }

    public function postActivation(ConnectionInterface $con = null)
    {
        $database = new Database($con->getWrappedConnection());

        $database->insertSql(null, [__DIR__ . '/Config/thelia.sql', __DIR__ . '/Config/insert.sql']);

        ConfigQuery::write('socolissimo_login', null, 1, 1);
        ConfigQuery::write('socolissimo_pwd', null, 1, 1);
        ConfigQuery::write('socolissimo_url_prod', "https://ws.colissimo.fr/pointretrait-ws-cxf/PointRetraitServiceWS/2.0?wsdl", 1, 1);
        ConfigQuery::write('socolissimo_url_test', "https://pfi.telintrans.fr/pointretrait-ws-cxf/PointRetraitServiceWS/2.0?wsdl", 1, 1);
        ConfigQuery::write('socolissimo_test_mode', 1, 1, 1);
    }

    public static function getModCode()
    {
        return ModuleQuery::create()->findOneByCode("SoColissimo")->getId();
    }

}
