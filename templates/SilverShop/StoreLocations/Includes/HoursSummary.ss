<dl class="store-hours-summary">
    <% loop $HoursSummary %>
        <dt>$Days</dt>
        <dd<% if $Closed %> class="is-closed"<% end_if %>>$Hours</dd>
    <% end_loop %>
</dl>
