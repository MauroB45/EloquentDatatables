## EloquentDataTables Package

Library to easily integrate jQuery DataTables in server side mode with Laravel. To check the DataTables docs: https://datatables.net

This package provide a nice interface to capture and use the request object sent by DataTables to build the Eloquent query dinamically. By doing this the information retreived from the DB is paginated, sorted and filtered based on the configuration. The server side mode in DataTable is commonly used when there is a large amount of data and the client rendering time takes a long time to execute. 

# Install

This package can be installed with Composer running the following command:

```php
  composer require MauroB45\EloquentDatatables
```
After installing the EloquentDatatables, register the `MauricioBernal\EloquentDatatables\EloquentDatatablesServiceProvider` in your config/app.php configuration file like:

```php
  'providers' => [
    // ...
    MauroB45\EloquentDatatables\EloquentDatatablesServiceProvider::class
  ],
```

# Use:

The basic usage of the package is

```php

public function getDataTable()
{
	return \Datatables::of("posts")->columns(['name', 'lastname', 'email'])->get();
}

```


## Contributing

Contributions are welcomed; to keep things organized, all bugs and requests should be opened on github issues tab for the main project in [Issues](https://github.com/MauroB45/EloquentAuditing/issues).

All pull requests should be made to the 'dev' branch, so they can be tested before being merged into master.


## License

The laravel-audit package is open source software licensed under the license MIT