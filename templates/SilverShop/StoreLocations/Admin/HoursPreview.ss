<div class="store-hours-preview">
    <p class="message notice"><%t SilverShop\StoreLocations\Model\StoreLocation.PreviewIntro 'This is how visitors will see the next 14 days, with seasons and exceptions applied. Save to update.' %></p>
    <table class="table">
        <thead>
            <tr>
                <th><%t SilverShop\StoreLocations\Model\StoreLocation.PreviewDay 'Day' %></th>
                <th><%t SilverShop\StoreLocations\Model\StoreLocation.PreviewHours 'Opening hours' %></th>
                <th><%t SilverShop\StoreLocations\Model\StoreLocation.PreviewSource 'Source' %></th>
            </tr>
        </thead>
        <tbody>
            <% loop $Days %>
                <tr<% if $IsException %> class="table-warning"<% end_if %>>
                    <td>$DayName $DateLabel<% if $IsToday %> <strong>(<%t SilverShop\StoreLocations\Page\StoreLocatorPage.Today 'Today' %>)</strong><% end_if %></td>
                    <td>$Text<% if $Reason %> <em>($Reason)</em><% end_if %></td>
                    <td>$SourceLabel</td>
                </tr>
            <% end_loop %>
        </tbody>
    </table>
</div>
