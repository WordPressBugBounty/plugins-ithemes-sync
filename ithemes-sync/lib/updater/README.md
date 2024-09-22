# updater

Updater is a shared git submodule used by all our products. This is our licensing system and updater package.

# Installation & Updates

Updater can be cloned locally using any development enviroment, we recommend [LocalWP](https://localwp.com/), [Lando](https://lando.dev), [Docker](https://www.docker.com/), [VVV](https://github.com/Varying-Vagrant-Vagrants/VVV), or another flavor of local development that's cool too!

# Developer Notes

To include this in your plugin follow these steps:

1. Add lib directory to product repository

`mkdir lib`

2. Add updater submodule

`git submodule add git@github.com:ithemes/updater lib/updater`
 
3. Add the following code to load the updater library in your plugin's main PHP file, replacing REPOSITORY NAME with the plugin's repository name

```
function ithemes_REPOSITORY_NAME_updater_register( $updater ) { 

    $updater->register( 'REPOSITORY-NAME', __FILE__ );
    
}
add_action( 'ithemes_updater_register', 'ithemes_REPOSITORY_NAME_updater_register' );
require( dirname( __FILE__ ) . '/lib/updater/load.php' );
 ```

4. Modify plugin or theme header information
 
```
/*
Plugin Name: Example Product
Plugin URI: http://solidwp.com/example-product/
Description: Example Product description.
Version: 1.0.0
Author: SolidWP.com
Author URI: http://solidwp.com/
iThemes Package: repository-name
*/
```

5. Verify and ship

As with any change of this nature, verify that everything works. Ensure that with the product active, that there aren’t any errors. Since this system works in a manner where only one copy of the library code will run (the most current version), a good check to do is to deactivate all other products that include this code to verify that everything works when using the library directly from the product that is being updated.

Once everything has been confirmed as working, release a new version as normal (assuming that the product is ready to be released).
