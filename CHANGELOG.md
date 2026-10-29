# Changelog

## [Unreleased]

### Fixed
- A booking to a pickup point names the point: its id (`Shipment::$pickupPoint`) is sent as
  shippingMultiParcelV4's `refValue.recipientRef`, where Chronopost reads the Chrono Relais point
  (as its own PrestaShop and Magento 2 modules send it). Only the relay product (`86`...) was sent
  before, never which point. The shipment's reference stays the shipper reference and the customer
  skybill number, and the recipient reference at home.
- `Api::civility()` is what the parties' XML uses (E for a company, M for a person), and its
  docblock says so (it spoke of a "3-digit type code"); `Mapping::valueXml()` loses its unused
  `$pro` parameter. No change in what is sent.
