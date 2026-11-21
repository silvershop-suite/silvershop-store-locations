<table class="store-hours">
    <caption class="store-hours__caption"><%t SilverShop\StoreLocations\Page\StoreLocatorPage.ComingWeek 'Opening hours for the coming week' %></caption>
    <tbody>
        <% loop $Week %>
            <tr class="store-hours__row<% if $IsToday %> is-today<% end_if %><% if $IsException %> is-exception<% end_if %><% if $IsClosed %> is-closed<% end_if %>">
                <th scope="row" class="store-hours__day">$DayName <span class="store-hours__date">$DateLabel</span></th>
                <td class="store-hours__time">$Text<% if $Reason %> <span class="store-hours__reason">$Reason</span><% end_if %></td>
            </tr>
        <% end_loop %>
    </tbody>
</table>
