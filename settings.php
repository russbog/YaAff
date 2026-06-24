<?php
$cloSettings =
[
//password for the cloaker's admin page
"adminPassword" => "12345qweasd",

//if you add a domain here, then only users from this domain will be able to access the admin page
//all others will get a 404
"adminDomain" => "",

//if you add an IP here, then only users from this IP will be able to access the admin page
//when behind Cloudflare, the real visitor IP will be taken from CF-Connecting-IP only for real Cloudflare proxy IPs
"adminIp" => "",

//master bearer token for the REST API (Phase 11). When set it grants full
//(super-admin) access via "Authorization: Bearer <token>". Leave empty to
//disable the master token and require a per-user api_token instead.
"apiToken" => "",

//WARNING:if you are using nginx either change your website's config so that it prevents people from
//downloading your database, or just rename the db file so security through obscurity will work! :-D
"dbConnection" => "clicks.db",

//database backend: "sqlite" (default) or "mysql" (MySQL/MariaDB) for scale.
//the schema is authored once in SQLite dialect and translated automatically.
"dbDriver" => "sqlite",

//connection details used only when "dbDriver" is "mysql". Keep credentials out
//of version control (use a local override or environment as appropriate).
"mysql" => [
    "host" => "127.0.0.1",
    "port" => 3306,
    "database" => "yaaff",
    "username" => "yaaff",
    "password" => "",
    "socket" => "",
],

//data retention in days for high-volume tables (clicks/blocked/trafficback and
//the audit logs). 0 disables automatic pruning. Run bin/run_retention.php (cron).
"retentionDays" => 0,

//if you want to automatically update MaxMind's geobases 
//then go to maxmind.com, register, get API key and put it here
"maxMindKey" => "",

//set to true if you want to use universal thankyou page (UTP) instead of the thankyou pages from your landings, 
//UTP autotranslates itself to the user's language and lets you effortlessy 
//manage pixels for Facebook/TikTok/Google and other sources.
"useUTP" => false,

//if true the cloaker will:
//- show PHP errors if any,
//- won't obfuscate any javascript code
//- add tracing to some javascripts (they will print info to browser console)
//- will add YWB headers to the response, where you'll be able to see, how long does it take to process requests
"debug" => true,

//root directory for all caches
"cachingDir" => "caching",

//folder where all landings and prelandings are stored (inside cachingDir)
"landingFolder" => "landings",

//folder where all white pages are stored (inside cachingDir)
"whiteFolder" => "whites",

//folder for caching CURL white page resources (inside cachingDir, auto-managed)
"whiteCurlCache" => "whites_curl",

//folder for DeviceDetector cache (inside cachingDir)
"devicesCache" => "devices",

//folder for currency rate cache (inside cachingDir)
"currencyCache" => "currency"
];

function get_cache_path(string $subKey): string {
    global $cloSettings;
    return $cloSettings['cachingDir'] . '/' . $cloSettings[$subKey];
}
