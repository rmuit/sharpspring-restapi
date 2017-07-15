# Sharpspring REST API PHP tools

This PHP library contains

- a simple client class which makes REST API calls (but contains no Sharpspring
specific logic, besides the URL and authentication parameters);
- a 'Connection' object which
  - contains wrapper functions for many API methods, with extensive method
  comments about anyting confusing that was found;
  - does strict checking of API responses and tries to abstract away the
  potentially confusing parts;
  - can help deal with custom fields;
- ValueObject / Lead classes which can help deal with custom fields.

It also contains several example/partial classes implementing a synchronization
process of contact data from a source system into Sharpspring's contact/leads
database. This works with a local cache of Sharpspring leads, to minimize update
calls to the Sharpspring REST API.

## Code principles

The client class can be used standalone, though this library wasn't written for
that. If you want to take care of building your own parameters and decoding the
result yourself: go ahead. Instantiate it; call the call() method. You don't
need the rest of the library.

The aim of the Connection class is to _help you not be confused_ about
communicating with Sharpspring's REST API. It tries to help with this in the
following ways:
- It tries to stay close to Sharpspring's documentation of the REST API, which
  is your primary source of information. So all methods documented in there,
  have equal methods on the Connection object.
- It does not wrap things that do not need to be wrapped:
  - The return value of calls is equal to the 'result' array in the API response
    because that is generally the only value you will need. Unless that result
    is a one-element array by definition; then that array is unwrapped.
  - It is sometimes beneficial to use objects/classes containing the result,
    e.g. when dealing with Leads. (Especially if you are using custom fields.)
    Classes are included to do this, but you are not _forced_ to use them. If
    you like, you can keep using the result array as it is returned by the
    Sharpspring API endpoint.
- Extensive checking of the response format is done. So if a call returns a
  result, you can be sure it's vetted. (Exceptions are thrown at any sign of
  trouble.)
- Extensive comments were added around 'non-obvious' behavior of the API that
  was observed.

(The LocalLeadCache class is not discussed here.)

## Usage

```
use SharpSpring\RestApi\Connection;
use SharpSpring\RestApi\CurlClient;

// Since the actual API call was abstracted into CurlClient, you need to
// instantiate two classes:
$client = new CurlClient(['account_id' => ..., 'secret_key' => ...);
$api = new Connection($client);
// Get all leads updated after a certain time (notation in UTC).
$leads = $api->getLeadsDateRange('2017-01-15 10:00:00');
```

The code throws exceptions for anything strange it encounters... except for one
thing: extra properties it sees in the response, besides the array value(s)
expected by the specific API/Connection method you are calling. These are
ignored by default; it is not expected that they will ever be encountered. If
you want to have these logged, then pass a PSR-3 compatible logger object as the
second argument to the Connection constructor.

### Value objects / custom properties
You don't have to use objects/classes for e.g. sending updates through the REST
API, but it can help write sane code, especially when you have custom fields.
The reason for this is documented in [the ValueObject comments](src/ValueObject.php);
here are some practical examples to complement the description. There are many
ways to do the same thing and you can choose your preferred approach.
```
/**
 * Say you have leads for your shoe store, with a custom field for shoe size
 * which you created through the Sharpspring UI.
 *
 * You can create leads with an array as input:
 */
$api->createLead([
    'firstName' => 'Roderik',
    'emailAddress' => 'rm@wyz.biz',
    'shoe_size_384c1e3eacbb3' => 12,
]);

/**
 * ...but as you extend your code, you might grow tired of the hardcoded
 * field name assigned by Sharpspring. You could also do this, since
 * createLead() accepts both objects and arrays:
 */
class ShoeStoreLead extends Lead
{
    // Define your own properties:
    public $shoeSize;

    // Override the parent's (empty) property mapping variable:
    protected $_customProperties = ['shoeSize' => 'shoe_size_384c1e3eacbb3'];
}

$lead = new ShoeStoreLead();
$lead->firstName = 'Roderik';
$lead->emailAddress = rm@wyz.biz';
$lead->shoeSize = 12;

$api->createLead($lead);

/**
 * If you have multiple Sharpspring accounts, you can e.g. set
 * $this->_customProperties dynamically in the ShoeStoreLead constructor and use
 * the same code to update both accounts.
 *
 * If you want to create a general shoestore PHP library, you probably want to
 * inject the account specific settings rather than hardcoding them:
 */
class ShoeStoreLead extends Lead
{
    public $shoeSize;
}
// The first argument are values for the new object; see below.
$lead = new ShoeStoreLead([], $my_properties); // see $_customProperties above
$lead->shoeSize = 12;
// etc
$api->createLead($lead);

/**
 * However, maybe this injection of custom properties into every single
 * ShoestoreLead grows tedious. Since the properties stay the same (until you
 * switch login), you can also set them once on a Connection:
 */
class ShoeStoreLead extends Lead
{
    public $shoeSize;
}
$api->setCustomProperties('lead', $my_properties);

$lead = new ShoeStoreLead();
$lead->shoeSize = 12;
// etc
$api->createLead($lead);

/**
 * This brings us full circle: if you don't like objects, you can also use
 * arrays with custom keys after setting them on the Connection.
 */
$api->setCustomProperties('lead', $my_properties);

$api->createLead([
    'firstName' => 'Roderik',
    'emailAddress' => 'rm@wyz.biz',
    'shoeSize' => 12,
]);
```
In summary: you can use both arrays and custom objects (where the former could
give you shorter code, and the latter could give you IDE autocompletion of
property names), and if you want to use custom property names / 'aliases' for
Sharpspring's custom field system names, you can set a field mapping in either
the Connection object or in each ValueObject.

Above is for constructing objects for create/update calls. When retrieving data
from the REST API, no conversion is done automatically. You get what is in the
JSON response (i.e. with custom system names), as an array.
```
$api->setCustomProperties('lead', $my_properties);

$lead_array = $api->getLead(123456);
// This returns ['emailAddress' => 'rm@wyz.biz', 'shoe_size_384c1e3eacbb3' => 12, ...]
// i.e. the above setCustomProperties() has no effect on this.

// If you want to use Lead objects (to be able to use $lead->shoeSize) then
// the conversion to 'fixed' property names will need to be done in one of
// several ways:
// 1) feed $lead_array into an object that knows about its own custom properties
//    because they are stored in the object, or they are injected (see above):
$lead = new ShoeStoreLead($lead_array, $my_properties); // $my_properties may or may not be needed

// 2) if you have stored the custom properties in the Connection object, you can
//    use a helper function to first convert the array keys:
$general_lead_array = $api->convertSystemNames('lead', $lead_array);
// This returns ['emailAddress' => 'rm@wyz.biz', 'shoeSize' => 12, ...] and
// can now be fed into the object that does not need to know its own field
// mapping:
$lead = new ShoeStoreLead($general_lead_array);
```
I have not used this conversion of data _from_ the API endpoint into
ValueObjects / arrays with 'non system' keys myself, yet. There may be a case
for extending getLead(s), getAccount(s) et al, with an option that returns
such arrays. Opinions/PRs welcome.

## API Bugs

Most strange behavior of the Sharpspring REST API has been documented or partly
mitigated/hidden away by this library. However if you are going to do serious
work based on the API, there are a couple of things you should at least be aware
of, and decide whether you need to take these into account.

1) Values with non-standard characters (roughly: characters that would be encoded
by htmlspecialchars()) are stored in Sharpspring differently depending on
whether they are inserted through the REST API or entered through the UI. (And
for the UI, things also differ between standard and custom fields.) The '<' is
even stranger: it's _sometimes_ stored double-encoded. The gory details are in
[encoding.md](encoding.md). The only way this library has been able to mitigate
that behavior is for CurlClient to always HTML-decode any fields, whether or not
it's necessary.
Because of the HTML decoding happening transparently, you likely won't see this
behavior, but a serious application should still consider whether this is a
problem.

2) The updateLead call can change e-mail addresses of an existing lead by
submitting (at least) the existing 'id' value along with the changed e-mail
address. However if the changed e-mail happens to be used in another existing
lead already, the API will silently discard the update _but still report
success_. This is a potential issue if you are mirroring an existing contact
database where e-mail addresses are not necessarily unique, into Sharpspring.
You will need to doublecheck your updates to see whether they succeeded. (One
example of such code is in SharpspringSyncJob::finish().)

## Completeness

This code has been tested with Leads and ListMembers. More API calls are present
but not all of them have been tested extensively and some are missing. Adding
new calls is hopefully not a lot of work; pull requests are welcomed.

## Authors

* Roderik Muit - [Wyz](https://wyz.biz/)

I like contributing open source software to the world and I like opening up
semi-closed underdocumented systems. Give me a shout-out if this is useful or if
you have a contribution. Contact me if you need integration work done. (I have
experience with several other systems.)

## License

This library is licensed under the MIT License - see the
[LICENSE.md](LICENSE.md) file for details.

## Acknowledgments

* Partly sponsored by [Yellowgrape](http://www.yellowgrape.nl/), professionals
  in E-commerce strategy / marketing / design. (The synchronisation process was
  paid by them; the added work of writing code that can be open-sourced and
  carefully testing/documenting things was done in my own unpaid time.)
