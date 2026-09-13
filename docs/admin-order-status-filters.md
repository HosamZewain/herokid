# Admin order status filters

The order list supports multiple selections for order, shipping, printing, and payment status. Open a filter, check one or more states, then press **تطبيق**. The control shows the selected state or selection count. **كل الحالات / مسح الاختيار** clears that filter; the page-level **مسح** resets the filters.

- Selected values within one filter are combined with OR.
- Different filters, search, and the current lifecycle/catalog tab are combined with AND, preserving existing filtering scope.
- The existing **حالات متعددة** order-status option still means a checkout with different order-record statuses. It can be combined with individual order statuses using OR; it is not the multiselect switch.
- Statistics, tag counts, pagination, and CSV export use the same filtered checkout query. A matching checkout appears only once, with its full contents.
- Query fields are `status[]`, `shipping_status[]`, `printing_status[]`, and `payment_status[]`. Existing scalar links such as `?status=new` remain supported. Empty selections mean no restriction for that field.
- Values are validated against the existing status registry, including inactive historical states. Unsupported or nested values receive normal validation errors.
- No schema migration or permission change is required. Committed frontend assets must be deployed with the Blade changes.

Regression coverage: `tests/Feature/AdminOrderMultiStatusFilterTest.php`.
