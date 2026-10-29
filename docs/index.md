# omnibus/chronopost

Chronopost for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): prices, skybills
with their labels (Chronofresh included), tracking, cancellation and Chronopost Pickup relays,
through Chronopost's SOAP 1.1 web services on `https://ws.chronopost.fr`. The envelopes are
written and read as XML over plain HTTP (`ext-soap` is not needed). The gateway calls:

| Operation | Web service | Path | Operation name |
|---|---|---|---|
| rate | Quickcost | `/quickcost-cxf/QuickcostServiceWS` | `calculateProducts` |
| ship | ShippingServiceWS | `/shipping-cxf/ShippingServiceWS` | `shippingMultiParcelV4` |
| track | TrackingServiceWS | `/tracking-cxf/TrackingServiceWS` | `trackSkybillV2` |
| cancel | TrackingServiceWS | `/tracking-cxf/TrackingServiceWS` | `cancelSkybill` |
| pickup points | PointRelaisServiceWS | `/recherchebt-ws-cxf/PointRelaisServiceWS` | `recherchePointChronopostInter` |

Every call is a `POST` with `SOAPAction: ""` and a 20-second timeout. Chronopost has no sandbox
endpoint: tests are made with its published test account on the same address.

## Installation

```sh
composer require omnibus/chronopost
```

Requires PHP 8.2, `omnisense/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8); given no
HTTP client, the factory makes one with `HttpClient::create()`. In a Symfony application with
`Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the package is
installed.

## Credentials

- The contract's **account number** (8 digits) and its **web services password**: both come with
  a Chronopost contract.
- A **sub-account** (3 digits), when the contract has several.

The account number and password travel inside each request's body (not for tracking, which
needs neither). Chronopost's published test account is `19869502` / `255562`
(`ChronopostGatewayFactory::TEST_ACCOUNT`, `ChronopostGatewayFactory::TEST_PASSWORD`): it issues
test skybills. Chronofresh needs its own contract (a food business, approved insulated packaging,
parcels handed over on the days the contract allows).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `account_number` | string | - | yes | the contract number |
| `password` | string | - | yes | its web services password |
| `sub_account` | ?string | `null` | no | sent as `subAccount` when booking |
| `rates` | list | - | no | prices from configuration instead of Quickcost ([the rates option](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/configuration.md#the-rates-option)): with it, Quickcost is not called |

Shipment options (`Shipment::$options`):

| Option | Read by | Default | |
|---|---|---|---|
| `products` | rate | all | a list of product codes: Quickcost's answer is cut to them |
| `label_format` | ship | `PDF` | `PDF` or `ZPL` (any other value is `PDF`) |
| `product` | ship | - | a Chronofresh range: `fresh`, `freeze` or `ambient` (below) |
| `use_by` | ship | - | the parcel's use-by date, a `DateTimeInterface` or a string PHP reads as a date; required for `fresh` and `freeze` |
| `sell_by` | ship | `use_by` | the sell-by date, when it differs |
| `saturday` | ship | `false` | `true`: Saturday delivery (service `6`) |

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | yes (Quickcost), or configured rates with `rates` |
| `Shipping` | yes |
| `Tracking` | yes |
| `Pickup` | yes (Chronopost Pickup relays) |
| `GetSlip` | no |
| `Cancel` | yes |
| `Paperless` | no |

## Behaviour

**Products.** The service is Chronopost's product code; labels come from
`Omnibus\Chronopost\Mapping::PRODUCTS`:

| Code | Product | |
|---|---|---|
| `01` | Chrono 13 | the default at home |
| `02` | Chrono 10 | |
| `16` | Chrono 18 | |
| `86` | Chrono Relais 13 | the default to a pickup point |
| `5X` | Chrono Relais Europe | |
| `17` | Chrono Classic | international |
| `44` | Chrono Express | |
| `2O` | Chrono 13 Relais | |
| `2R` | Chrono Fresh 13 | Chronofresh, 0 to 4 °C |
| `6S` | Chrono Fresh Relais 13 | Chronofresh, to a relay |
| `2S` | Chrono Freeze 13 | Chronofresh, -18 °C |
| `5M` | Chrono Ambient 13 | Chronofresh, dry food |
| `5Q` | Chrono Ambient Relais 13 | Chronofresh, dry food to a relay |

**Rate.** `calculateProducts` from the sender's country and postcode to the recipient's country,
postcode and city, type `R` (relay) when the shipment has a `pickupPoint`, else `D`, the total
weight in kilograms with two decimals (at least 0.10). Every product Chronopost returns is a rate,
cut to the `products` option when it is set; `Shipment::$service` does not filter them. The amount
is `amountTTC` (taxes included) when given, else `amount`, in `EUR`; `days` is `null`. The label is
Chronopost's `productLabel`, else the table above, else `Chronopost <code>`. `toPickupPoint` is
`true` for a delivery mode `R` and for the codes `86`, `5X` and `2O`. Rates come cheapest first;
a product without an amount is left out.

**Ship.** One `shippingMultiParcelV4` call for all the parcels:

- the product is `Shipment::$service` when set, else the `product` range's code (at home, or to a
  relay when the shipment has a `pickupPoint`), else `86` to a pickup point and `01` at home;
- the sender is both the shipper and the customer; each party's civility is `E` with a company,
  `M` without; the name is the company (else the person) and the second name the person when
  there is a company, both cut to 100 characters, street lines to 38, the city to 50; the phone
  is sent as given, as phone and as mobile; the recipient type is `1` (company) or `2` (private);
- the shipment's `reference` (35 characters) is sent as shipper reference and customer skybill
  number, and as recipient reference at home; to a pickup point the recipient reference is the
  point's id (`Shipment::$pickupPoint`, an `identifiant` from the relay search), which is how
  Chronopost knows the Chrono Relais point to deliver to;
- each parcel is a `skybillValue`: its rank, weight in kilograms (at least 0.10), dimensions when
  all three are given, its declared `value` as customs value in its currency, object type `MAR`,
  no cash on delivery and no insurance;
- the ship date is `Shipment::$shippingDate`, else now, and the ship hour the current hour;
- `withReservation` 0, `modeRetour` 2, `multiParcel` `Y` for more than one parcel;
- with a `pickupPoint`, an `esdValue` block whose retrieval and closing times are the ship date.

The tracking number is the first `skybillNumber` returned; the label is its `pdfEtiquette` (else
`skybill`) base64-decoded, `application/pdf` or `application/zpl`; `trackingUrl` is
`https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT=<number>`. A response
without a number is a `CarrierException` (`Chronopost issued no skybill.`).

**Chronofresh.** Food under controlled temperature. The `product` option picks the range:

| `product` | At home | To a relay | |
|---|---|---|---|
| `fresh` | `2R` | `6S` | 0 to 4 °C |
| `freeze` | `2S` | - (refused) | -18 °C |
| `ambient` | `5M` | `5Q` | dry food |

A `service` named on the shipment wins over the range. The use-by date goes as `scheduledValue`
(`expirationDate`, `sellByDate`, both `yyyy-mm-dd`); dry food carries one only when given one.
Before any call, a `2R`, `6S` or `2S` parcel without `use_by`, or whose `use_by` is not later than
the shipping day, is refused with a `CarrierException`, as is an unknown range or `freeze` to a
relay. `saturday` sets the service to `6` (else `0`). Quickcost prices a range with
`products: ['2R']` when the contract has it.

**Track.** `trackSkybillV2` in `fr_FR` when the locale starts with `fr`, else `en_GB`. The events
(`listEventInfoComp`, else `listEvents`) are read with their date as Chronopost writes it,
Chronopost's event code kept as `code`, `<postcode> <office>` as `location`, and sorted oldest
first. The status is the latest event's (`UNKNOWN` without events), mapped from its code, else
from words of its text:

| Code | Or the text contains | `TrackingStatus` |
|---|---|---|
| `D`, `DI`, `DL`, `DP`, `DR` | `livré`, `delivered` | `DELIVERED` |
| `SD`, `DE` | `en cours de livraison`, `out for delivery` | `OUT_FOR_DELIVERY` |
| `RD`, `RB`, `MA` | `disponible`, `available` | `AVAILABLE_FOR_PICKUP` |
| `RE`, `RT`, `RTS` | `retour`, `return` | `RETURNED` |
| `AE`, `AR`, `IN`, `AN`, `EXC` | `anomalie`, `incident`, `absent` | `EXCEPTION` |
| `PC`, `PCH`, `TA` | `préparation`, `annoncé`, `pris en charge` | `PENDING` |
| any other code | | `IN_TRANSIT` |
| no code | | `UNKNOWN` |

The rows are tried in this order: a text containing `livré` is `DELIVERED` whatever its code.

**Pickup points.** `recherchePointChronopostInter` with the address's first street line, postcode,
city and country, type `P`, product `86`, service `L`, the parcel's weight in grams (1000 without
a parcel), today's date, at most `min(50, limit)` points within 20 km, holidays tolerated. Each
point: Chronopost's `identifiant` as id, its name, two address lines, country (the searched one
when missing), coordinates, `distanceEnMetre` as `distance`. Opening hours are read from each
`jour` and its `horairesAsString` (`08:00-12:30 14:00-19:30`): a slot closing at `00:00` is a
closed day and left out. The points are kept in Chronopost's order and cut to `limit`.

**Cancel.** `cancelSkybill` for the skybill (language `fr_FR`): `true` when Chronopost answers
error code `0`. Any other code is raised as an error, so a cancellation is `true` or an exception.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Chronopost\ChronopostGatewayFactory;
use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Symfony\Component\HttpClient\HttpClient;

$chronopost = (new ChronopostGatewayFactory(HttpClient::create()))->create([
    'account_number' => ChronopostGatewayFactory::TEST_ACCOUNT,   // the contract's, in production
    'password' => ChronopostGatewayFactory::TEST_PASSWORD,
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['12 rue de la Paix'], '75002', 'Paris', 'FR', company: 'Glitch Art', phone: '+33102030405'),
    new Address('Émile Zola', ['21 bis rue de Bruxelles'], '75009', 'Paris', 'FR', email: 'emile@example.org', phone: '0612345678'),
    [new Parcel(800, 30, 20, 10)],
    service: '01',
    reference: 'CMD-1042',
    options: ['label_format' => 'PDF'],
);

foreach ($chronopost->rate($shipment) as $rate) {
    printf("%s %-18s %6.2f %s\n", $rate->service, $rate->label, $rate->amount / 100, $rate->currency);
}

$label = $chronopost->ship($shipment);
file_put_contents($label->trackingNumber.'.pdf', $label->content);
echo $label->trackingNumber, ' ', $label->trackingUrl, "\n";

$tracking = $chronopost->track($label->trackingNumber, 'fr');
echo $tracking->status->value, ' ', $tracking->latest()?->description, "\n";

foreach ($chronopost->pickupPoints($shipment->recipient, 3) as $point) {
    printf("%s %s %d m\n", $point->id, $point->name, $point->distance);
}

var_dump($chronopost->cancel($label->trackingNumber));
```

In Symfony:

```yaml
omnibus:
    gateways:
        chronopost:
            factory: chronopost
            options:
                account_number: '%env(CHRONOPOST_ACCOUNT)%'
                password: '%env(CHRONOPOST_PASSWORD)%'
                sub_account: null
```

A Chronofresh skybill:

```php
$label = $chronopost->ship(new Shipment($sender, $recipient, [new Parcel(2500)], options: [
    'product' => 'fresh',                 // 2R at home, 6S with a pickupPoint
    'use_by' => '2026-10-11',             // the shortest use-by date of what the parcel holds
    'saturday' => false,
], shippingDate: new \DateTimeImmutable('2026-10-06 10:00')));
```

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `chronopost`:

- an `errorCode` other than `0` in the answer: Chronopost's `errorMessage` (else
  `Chronopost error <code>`), the code in `carrierCode`;
- a SOAP fault: its `faultstring`;
- a network error: `Chronopost request failed: ...`;
- a body that is not XML: `Chronopost answered HTTP <status> with a body that is not SOAP.`;
- an HTTP status of 400 or more, or an answer without its `return` element: `HTTP <status>`;
- before any call, the Chronofresh refusals above and `Chronopost issued no skybill.` after it.

A `use_by` or `sell_by` string PHP cannot read as a date throws PHP's own date exception, not a
`CarrierException`. A missing `account_number` or `password` is an `InvalidConfigException` when
the gateway is built; `GetSlip` and `Paperless` raise `RequestNotSupportedException` before any
call.

No failure carries the password: a network error's message has them masked as `***` (the key
parameters of a URL too), its previous is a copy of the HTTP client's exception (the same class and
code), and no trace of the chain keeps its arguments, where the request's options stood ([No secret
in a
failure](https://github.com/glitchr-omnisense/omnibus/blob/2.x/docs/errors.md#no-secret-in-a-failure)).

## Verified, and not

Tested offline: `Tests/ChronopostGatewayTest.php` (Quickcost's products cheapest first with taxes
included and the relay flag, the account in the body, the weight in kilograms; a skybill with its
PDF, the company as name and the person as second name; Chronofresh ranges at home and to a relay,
the use-by and sell-by dates, the Saturday service, a booking to a pickup point naming the point
as recipient reference, a service winning over the range, chilled
parcels refused before any call; tracking oldest first with its location, relays with their
distance and hours, Sunday closed, the cancellation; an error code raised with its message) and
`Tests/ChronopostContractTest.php`, the family's conformance suite on recorded answers. The example
above was run in the core's harness on those recorded answers.

Not verified: the README says the package is built from Chronopost's published web services
documentation and was not yet run against the test account. Chronofresh is not verified against a
real contract: its codes and fields come from public integrations of Chronopost Food, and the
order of `scheduledValue` in the request and the Saturday service are to be confirmed on a first
real skybill. The weekday numbering of the relays' `jour` (taken as ISO, 1 = Monday) is unchecked
against a real answer. The pickup point's id travels as `recipientRef`, as Chronopost's own
PrestaShop and Magento 2 modules send it; not yet checked on a real skybill.

## Responsibility

An independent, unofficial client, not affiliated with Chronopost SAS (La Poste group). Whoever
runs it complies with Chronopost's terms ([chronopost.fr general conditions of
use](https://www.chronopost.fr/en/general-conditions-use)): see the README's Responsibility
section.
