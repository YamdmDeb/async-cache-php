# Serialization

By default, the library uses native PHP serialization (`serialize`/`unserialize`) to store data. However, you can switch to other serializers for better performance, security, or interoperability.

## Available Serializers

The library includes several built-in serializers in `Fyennyi\AsyncCache\Serializer`:

1.  **`PhpSerializer`** (Default): Uses standard PHP serialization. Best compatibility.
2.  **`JsonSerializer`**: Stores data as JSON. Good for debugging and cross-language compatibility.
    -   *Note*: Does not support objects unless they implement `JsonSerializable`.
3.  **`IgbinarySerializer`**: Uses the `igbinary` extension (if available). Significantly smaller and faster than standard PHP serialization.
4.  **`EncryptingSerializer`**: Wraps another serializer and encrypts the output using OpenSSL (AES-256-CBC).

## Configuring Serialization

Use `withSerializer` on the builder.

### Using JSON

```php
use Fyennyi\AsyncCache\Serializer\JsonSerializer;

$manager = AsyncCacheBuilder::create($adapter)
    ->withSerializer(new JsonSerializer())
    ->build();
```

### Using Encryption

```php
use Fyennyi\AsyncCache\Serializer\EncryptingSerializer;
use Fyennyi\AsyncCache\Serializer\PhpSerializer;

$key = 'super-secret-key-must-be-32-bytes'; // 32 chars for AES-256

$serializer = new EncryptingSerializer(
    new PhpSerializer(),
    $key
);

$manager = AsyncCacheBuilder::create($adapter)
    ->withSerializer($serializer)
    ->build();
```

## Compression

Independent of the serializer, you can enable **GZIP compression** for large items via `CacheOptions`.

```php
$options = new CacheOptions(
    ttl: 3600,
    compression: true,
    compression_threshold: 1024 // Only compress if larger than 1KB
);
```
