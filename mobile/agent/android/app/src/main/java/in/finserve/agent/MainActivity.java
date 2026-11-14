package in.finserve.agent;

import android.os.Bundle;
import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Printing receipts and statements (print or Save as PDF) from inside the app.
        registerPlugin(PrintPlugin.class);
        super.onCreate(savedInstanceState);
    }
}
