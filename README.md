## Scheduled imports

Enable both the feed and its schedule, and select at least one day and a time in the WordPress site timezone. Scheduled imports are queued on the first WordPress Cron check at or after the selected time on that day. A failed enqueue is shown in the feed's last-run result and is not retried until the next scheduled day.

WordPress Cron depends on site traffic unless a server cron triggers `wp-cron.php`; use a server cron for reliable operation. Scheduled jobs appear in **Import jobs**, where their progress can be monitored.

The import log, failure details, and recent jobs refresh every 10 seconds without reloading the feed form. The log viewer retains its feed filter and displays up to 250 recent entries.

To clear a stalled job and its logs, use **Cancel and clear imports for this feed**. This cancels that feed's active jobs and deletes their rows and logs without removing imported products. A row already being processed may still finish. If a worker lock is present, the jobs are cancelled but not immediately deleted; after the worker stops or its lock expires, use **Clear completed jobs and logs**. Cancellation or cleanup errors are shown in the admin interface.

## XML feed column troubleshooting

XML feeds often wrap products in one or more container nodes (including namespaced Atom/OData responses). The importer now looks for repeated product-like elements and descends through wrapper nodes instead of treating each root child as a separate product row. XML field names are flattened into paths such as `ProductDto.Name`, `ProductDto.Sku`, or `@id`.

If automatic detection still selects the wrong nodes, choose **XML item XPath** in the feed form, enter the path for the repeated product/item element, then click **Preview columns** again. Common examples are `//Product`, `//ProductDto`, `//product`, `/Products/Product`, and `//entry`. For namespace-specific XML, a local-name XPath such as `//*[local-name()="ProductDto"]` can be used. The preview should show product fields (not wrapper fields such as `Href` or `Relations`) before mapping.

The `Products` URL in the screenshot appears to return an XML service/collection wrapper, not a flat product list. Try the XPath control with `//ProductDto` first; if that does not match, inspect the feed XML and select its actual repeated product element. The importer does not infer a supplier's exact schema from the URL alone.
