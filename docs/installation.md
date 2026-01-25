# Installation

The recommended way to install the library is via [Composer](https://getcomposer.org/).

## Requirements

- **PHP**: 8.1 or higher.
- **Composer**: For dependency management.

## Installation

=== "Composer (Recommended)"

    Run the following command in your terminal:

    ```bash
    composer require fyennyi/async-cache-php
    ```

=== "Git / Manual"

    1. Clone the repository:
       ```bash
       git clone https://github.com/Fyennyi/async-cache-php.git
       cd async-cache-php
       ```

    2. Install dependencies:
       ```bash
       composer install
       ```

    3. Include the autoloader in your project:
       ```php
       require_once 'async-cache-php/vendor/autoload.php';
       ```

## Post-Installation

Once installed, you can start using the library by including the Composer autoloader in your script:

```php
<?php

require 'vendor/autoload.php';

use Fyennyi\AsyncCache\AsyncCacheManager;
```