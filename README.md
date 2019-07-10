### Install headless browser (Google Chrome) for AWS Linux, RHEL/CentOS 6.X/7.X

`curl https://intoli.com/install-google-chrome.sh | bash`

### Update environment variables

- Linux
  `CHROME_PATH=/bin/google-chrome`
- Windows
  `CHROME_PATH=/ProgramFiles/Google/Chrome/Application/chrome`

### Usage

- `php scrape livescore` - will call Livescore::class on `Components\Development\Livescore.php`
- `php scrape career` - will call Career::class on `Components\Development\Career.php`

## NOTE:

- Create a class that will also be used to call in CLI mode.

```

<?php
namespace Scraper\Components\Development;

use Scraper\Interfaces\Database;
use Scraper\Interfaces\Controller;

class Career implements Controller {
        public function \_\_construct(Database $database, $param = []) {
        dump('Career!');
        exit;
        }
}
```

## All created class should implement Controller interface.

---

```
<?php

namespace Scraper\Interfaces;

use Scraper\Interfaces\Database;

interface Controller {
  public function __construct(Database $database, $param = []);
}
```
