<% with $Location %>
<article class="store-location">
    <header class="store-location__header">
        <h1 class="store-location__title">$Title</h1>
        <% include SilverShop\StoreLocations\Includes\OpeningStatus %>
    </header>

    <% if $Image %><div class="store-location__image">$Image.ScaleMaxWidth(1200)</div><% end_if %>
    <% if $Intro %><p class="store-location__intro">$Intro</p><% end_if %>

    <div class="store-location__grid">
        <section class="store-location__section store-location__contact">
            <h2><%t SilverShop\StoreLocations\Page\StoreLocatorPage.Address 'Address' %></h2>
            <address>$FormattedAddress</address>
            <ul class="store-location__links">
                <% if $PhoneLink %><li><a href="$PhoneLink">$Phone</a></li><% end_if %>
                <% if $Email %><li><a href="mailto:$Email.ATT">$Email</a></li><% end_if %>
                <% if $ContactUrl %><li><a href="$ContactUrl.ATT" rel="noopener"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.Contact 'Contact' %></a></li><% end_if %>
                <li><a href="$DirectionsLink.ATT" target="_blank" rel="noopener"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.Directions 'Directions' %></a></li>
            </ul>
            <% if $Features %>
                <ul class="store-location__features">
                    <% loop $Features %>
                        <li><% if $IconClass %><span class="$IconClass" aria-hidden="true"></span> <% end_if %>$Title</li>
                    <% end_loop %>
                </ul>
            <% end_if %>
        </section>

        <section class="store-location__section store-location__hours">
            <h2><%t SilverShop\StoreLocations\Page\StoreLocatorPage.OpeningHours 'Opening hours' %></h2>
            <% include SilverShop\StoreLocations\Includes\OpeningHoursTable %>
            <% include SilverShop\StoreLocations\Includes\UpcomingExceptions %>
            <h3><%t SilverShop\StoreLocations\Page\StoreLocatorPage.RegularHours 'Regular opening hours' %></h3>
            <% include SilverShop\StoreLocations\Includes\HoursSummary %>
        </section>
    </div>

    <% if $Content %><div class="store-location__content">$Content</div><% end_if %>
</article>
<% end_with %>

$LocationJsonLd

<p class="store-location__back"><a href="$Link"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.AllLocations 'All locations' %></a></p>
