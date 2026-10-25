<?php

namespace Omnibus\Chronopost\Tests;

use Omnibus\Chronopost\ChronopostGatewayFactory;
use Omnibus\GatewayFactoryInterface;
use Omnibus\Testing\GatewayContractTests;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The conformance suite on Chronopost's recorded answers (SOAP web services, the published test account). */
final class ChronopostContractTest extends TestCase
{
    use GatewayContractTests;

    protected function gatewayFactory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new ChronopostGatewayFactory($http);
    }

    protected function gatewayOptions(): array
    {
        return ['account_number' => ChronopostGatewayFactory::TEST_ACCOUNT, 'password' => ChronopostGatewayFactory::TEST_PASSWORD];
    }

    protected function contractTrackingNumber(): string
    {
        return 'XY123456789FR';
    }

    protected function recordedAnswers(): array
    {
        $soap = static fn (string $operation, string $inner) => '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body><ns1:'.$operation.'Response xmlns:ns1="http://cxf.soap.chronopost.fr/"><return><errorCode>0</errorCode>'.$inner.'</return></ns1:'.$operation.'Response></soap:Body></soap:Envelope>';
        $relay = static fn (string $id, int $metres) => '<listePointRelais><identifiant>'.$id.'</identifiant><nom>RELAIS '.$id.'</nom><adresse1>19 RUE DE BRUXELLES</adresse1><adresse2></adresse2><codePostal>75009</codePostal><localite>PARIS</localite><codePays>FR</codePays><coordGeolocalisationLatitude>48.8820</coordGeolocalisationLatitude><coordGeolocalisationLongitude>2.3320</coordGeolocalisationLongitude><distanceEnMetre>'.$metres.'</distanceEnMetre><listeHoraireOuverture><jour>1</jour><horairesAsString>08:00-12:30 14:00-19:30</horairesAsString></listeHoraireOuverture><listeHoraireOuverture><jour>7</jour><horairesAsString>00:00-00:00</horairesAsString></listeHoraireOuverture></listePointRelais>';

        return [
            'body:calculateProducts' => $soap('calculateProducts', '<productList><productCode>01</productCode><productLabel>Chrono 13</productLabel><amount>12.50</amount><amountTTC>15.00</amountTTC><deliveryMode>D</deliveryMode></productList><productList><productCode>86</productCode><productLabel>Chrono Relais 13</productLabel><amount>7.90</amount><amountTTC>9.48</amountTTC><deliveryMode>R</deliveryMode></productList>'),
            'body:shippingMultiParcelV4' => $soap('shippingMultiParcelV4', '<resultMultiParcelValue><skybillNumber>XY123456789FR</skybillNumber><pdfEtiquette>'.base64_encode('%PDF-1.4 chrono').'</pdfEtiquette></resultMultiParcelValue>'),
            'body:trackSkybillV2' => $soap('trackSkybillV2', '<listEventInfoComp><events><code>D</code><eventDate>2026-10-02T11:30:00+02:00</eventDate><eventLabel>Colis livré</eventLabel><officeLabel>PARIS 09</officeLabel><zipCode>75009</zipCode></events><events><code>PC</code><eventDate>2026-10-01T17:00:00+02:00</eventDate><eventLabel>Colis pris en charge</eventLabel><officeLabel>PARIS 02</officeLabel><zipCode>75002</zipCode></events></listEventInfoComp>'),
            'body:recherchePointChronopostInter' => $soap('recherchePointChronopostInter', $relay('P12345', 120).$relay('P12346', 450).$relay('P12347', 900)),
            'body:cancelSkybill' => $soap('cancelSkybill', '<errorMessage></errorMessage>'),
        ];
    }
}
