# Character-encoding issues in field values

There are several issues with too much or too little escaping of values, in HTML
or JSON format... which are intermingled so I get confused unless I write down
all the details. The conclusions will be the important thing, but I need the
rest as a form of longer-term memory.

(tested 20170430; the API version is 1.117 but I am guessing the UI behavior is
probably not covered by the API version.)


### Testing: updates through the UI

In the UI's contact manager, in a standard Lead field (e.g. company name) enter
the following value:

```& < > " \ '`+=?/#%$@!*()~_ &amp; &lt; <```

After pressing the save button, you see

```& < > " \ '`+=?/#%$@!*()~_ & < <```

But _after reloading the page_, the screen will show:

```& &lt; > " \ '`+=?/#%$@!*()~_  &amp; &lt; &lt;```

When entering the original value in a custom field, saving (which does not make
the screen change) and then reloading, the screen will show:

```& < > \" \\ '`+=?/#%$@!*()~_ & < <```

(...because of this UI bug, if you then save the custom field value again
-unmodified- and reload the page, it will show:
```& < > \\\" \\\\ '`+=?/#%$@!*()~_ & < <``` )

Retrieving the (originally entered) values through the **REST API** will return
(after decoding the JSON), for the standard field:

```&amp; &amp;lt; &gt; &quot; \ &#039;`+=?/#%$@!*()~_ &amp;amp; &amp;lt; &amp;lt;```

And for the custom field:

```& &lt; > " \ '`+=?/#%$@!*()~_ &amp; &lt; &lt;```

(..and because of the aforementioned UI bug: after reloading the screen and
saving the custom field again, the REST API will return:
```& &lt; > \" \\ '`+=?/#%$@!*()~_ & &lt; &lt;``` )

### Testing: updates through REST API updateLead()

When setting the original value (see above) through the REST API in the two
fields:

The UI will show for the standard field:

```& < > " \ '`+=?/#%$@!*()~_ & < <```

(...and the UI has a bug where it does not escape the double quote, so if you
try to edit the value, you get the following: ```& < > ```)

And for the custom field:

```& < > \" \\ '`+=?/#%$@!*()~_ & < <```

Reading these value back through the **REST API** yields the same value for
_both_ fields:

```& &lt; > " \ '`+=?/#%$@!*()~_ &amp; &lt; <```

### Assumptions looking at the above

We can't look in the database to see how values are stored, but from above
inputs/outputs we can try to deduce what is happening. (We'll get the
obvious UI-only errors out of the way first.)

1. There's a consistent error with the _custom_ fields being 'too much' escaped
when they are output in the browser's contact manager for display. (This is true
for editable as well as non-editable fields.) It seems like there might be
unnecessary JSON encoding going on there. Unfortunately this is a nasty bug
because it changes data on re-saving a value.

2. The 'value="..."' part in the edit element of a _standard_ field is
erroneously _not_ escaped (as a HTML attribute). Unfortunately this is also a
nasty bug because it loses data on re-saving a value.

3. Between inserting a value through the REST API and retrieving that, few
things are going wrong, which means almost nothing is being erroneously encoded
on the path REST API -> storage -> REST API.
   * We can't be sure that there is no encoding/decoding going on between the
     REST API and the data storage, but it seems unlikely. If there was
     html-decode-upon-save & html-encode-upon-query, that would make the REST
     API output different. And html-encode-upon-save (and decode-upon-query) is
     theoretically possible but just wouldn't make any sense.
   * _Unexplained phenomenon_: above and more (undocumented) tests show that
     _only_ the `<` character gets erroneously HTML-encoded, _except when_ it is
     the last character in the string (or only followed by spaces).

4. The path REST API -> storage -> UI, by contrast, has too much decoding / not
enough encoding. Given the previous point, 'too much decoding' before saving
data seems unlikely - so we might assume that the output of loaded data to the
browser is not HTML-encoded when it should be (and so the `&lt;` and `<` get
rendered as the same thing by the browser).

5. The path UI -> storage -> REST API:
   * has different results for standard vs. custom fields, while REST API ->
     storage -> REST API is the same. Since there likely is no difference
     between outputting standard vs. custom fields through the REST API, the
     difference is likely in encoding the field values _before_ they get saved.
   * It seems likely that _standard_ fields values input through the UI are
     saved in the database HTML-encoded. (Both given the above subpoint, and
     given point 3 which seems to indicate no encoding is done on outputting
     loaded data through the REST API except maybe the strange issue with`<`).
   * _Unexplained phenomenon_: for _standard_ fields, both `<` in the string get
     double-encoded.
   * _Unexplained phenomenon_: for _custom_ fields, both `<` in the string get
     encoded (and no other characters do). This is inconsistent with point 3
     (for REST API input, the last `<` does _not_ get encoded on output).

6. The issues on the path UI input -> storage -> UI are unsurprising:
   * for _standard_ fields:
     * only the encoding of `<` as `&lt;` is an issue;
     * the `<` at the end of the string also gets encoded, which is consistent
       with 5 (path UI -> storage -> REST API at least for _custom_ fields) and
       inconsistent with 3 (REST API -> storage -> REST API).
   * for _custom_ fields:
     * too much JSON encoding, as mentioned in point 1.
     * too much decoding / not enough encoding, as mentioned in point 4.

### Conclusions

* The extra encoding of just the `<` character is a mystery.
  * The inconsistency between 3 (REST -> REST: last `<` not encoded) vs. 5/6 (UI
    -> REST / UI -> UI; last `<` also encoded) seems to point into the direction
    that this encoding is also happening before save, though I can't be sure.
* Disregarding that... it seems like _standard_ fields _entered in the UI_
    are HTML-encoded before they are saved. And custom fields / anything entered
    through the REST API, is not.
  * That's a bummer: it means real data inconsistency, and it's not something we
    can fix by adjusting the behavior of our PHP code. (If we HTML-encode all
    values we send in, that would create differences with custom fields entered
    through the UI. We can HTML-encode only the )
* This seems to be a real bug. If the UI input was consistently HTML-encoded
  (for both standard and custom fields) then I might assume it was a design
  decision. (Albeit a very strange one, IMHO.) But it is not.

It seems that the best thing our REST API client can do is just assume that
field values _might_ be either fully or partly HTML-encoded (the `<` only), or
even partly double-HTML-encoded (the `<` only)... and decode all string values
in a response before returning them. (Given the fact that a double-decode does
not harm anything. Except it decodes values that are _meant_ to be encoded as
HTML... but it seems unlikely that anyone would want this.)

We might also add an option to our Connection/Client object, to HTML-encode all
values before sending them in. (Or, to be 'consistent with the inconsistency' in
the UI, encode only standard fields.) But I'd need a more definite insight into
how Sharpspring treats its values (and whether this is also the case for other
object types than just leads), before that would be useful.
