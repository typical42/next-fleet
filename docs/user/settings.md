# Settings

Your NextFleet settings are on the Nextcloud settings page. To open them, click **Settings** in the
NextFleet navigation. The settings are in the **NextFleet** section of
**Personal settings** > **Additional settings**.

Each setting saves immediately.

## NextFleet settings

| Setting | Description |
|---|---|
| **Country** | The country for the vehicles that you add from now on: **Germany** or **Generic**. It sets their units, currency and rules. The vehicles that you have now keep their country. |
| **I reclaim VAT** | Select it if you get the VAT back, for example as a business. The cost figures then show net of VAT. |
| **Grid factor for charging, g CO₂/kWh** | The CO₂ emission of your electricity, in whole grams per kWh. Keep it empty to use the average of the country of each vehicle. |
| **Inbox folder** | The folder for the [receipt inbox](documents.md#use-the-receipt-inbox). To select it, click **Choose folder**. To stop the inbox, click **Stop using it**. |

## Connect a phone app or a script

NextFleet has an API for other apps. An app signs in with a Nextcloud app password, not with your
password. The app has the same access to vehicles as you.

There are two ways to give an app access:

- The app opens the Nextcloud login in your browser. You log in and grant access.
- You make an app password yourself:
  1. Go to **Personal settings** > **Security**.
  2. In **Devices & sessions**, type a name for the app.
  3. Click **Create new app password**.
  4. Type the login and the password that Nextcloud shows into the app.

To stop the access of an app, delete its app password in **Devices & sessions**.

Developers find the API reference in [api.md](../api.md).
