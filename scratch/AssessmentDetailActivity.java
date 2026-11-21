package com.social7.studentlearnanalytics.student.assignment;

import android.os.Bundle;
import android.view.MenuItem;
import android.widget.ArrayAdapter;
import android.widget.Spinner;
import android.widget.TextView;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.appcompat.widget.Toolbar;
import androidx.recyclerview.widget.LinearLayoutManager;
import androidx.recyclerview.widget.RecyclerView;
import com.social7.studentlearnanalytics.R;
import com.social7.studentlearnanalytics.adapter.AssessmentAdapter;
import com.social7.studentlearnanalytics.model.Assessment;
import com.social7.studentlearnanalytics.network.ApiClient;
import com.social7.studentlearnanalytics.network.ApiService;
import com.google.gson.JsonObject;
import com.google.gson.JsonArray;
import com.google.gson.JsonElement;
import retrofit2.Call;
import retrofit2.Callback;
import retrofit2.Response;
import android.widget.Toast;
import java.text.SimpleDateFormat;
import java.util.Locale;
import java.util.Date;
import java.util.ArrayList;
import java.util.List;

public class AssessmentDetailActivity extends AppCompatActivity {

    private RecyclerView recyclerView;
    private AssessmentAdapter adapter;
    private List<Assessment> assessmentList;
    private Spinner semesterSpinner;
    private TextView averageScoreText;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_assessment_detail);

        initViews();
        setupToolbar();
        setupSemesterSpinner();
        setupRecyclerView();
        fetchAssignments();
    }

    private void initViews() {
        recyclerView = findViewById(R.id.assessment_recycler_view);
        semesterSpinner = findViewById(R.id.semester_spinner);
        averageScoreText = findViewById(R.id.average_score_text);
    }

    private void setupToolbar() {
        Toolbar toolbar = findViewById(R.id.toolbar);
        setSupportActionBar(toolbar);
        if (getSupportActionBar() != null) {
            getSupportActionBar().setDisplayHomeAsUpEnabled(true);
            getSupportActionBar().setTitle("Assignments");
        }
    }

    private void setupSemesterSpinner() {
        String[] semesters = {"Semester 1, 2024", "Semester 2, 2023", "Semester 1, 2023"};
        ArrayAdapter<String> spinnerAdapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_item, semesters);
        spinnerAdapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item);
        semesterSpinner.setAdapter(spinnerAdapter);
    }

    private void setupRecyclerView() {
        assessmentList = new ArrayList<>();
        adapter = new AssessmentAdapter(assessmentList);
        recyclerView.setLayoutManager(new LinearLayoutManager(this));
        recyclerView.setAdapter(adapter);
    }

    private void fetchAssignments() {
        ApiService apiService = ApiClient.getApiService(this);
        Call<JsonObject> call = apiService.getStudentAssignments();
        
        call.enqueue(new Callback<JsonObject>() {
            @Override
            public void onResponse(Call<JsonObject> call, Response<JsonObject> response) {
                if (response.isSuccessful() && response.body() != null) {
                    assessmentList.clear();
                    JsonArray dataArray = response.body().getAsJsonArray("data");
                    SimpleDateFormat inputFormat = new SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSSSSS'Z'", Locale.getDefault());
                    SimpleDateFormat outputFormat = new SimpleDateFormat("MMM dd, yyyy", Locale.getDefault());

                    for (JsonElement element : dataArray) {
                        JsonObject obj = element.getAsJsonObject();
                        String id = obj.get("id").getAsString();
                        String title = obj.get("title").getAsString();
                        
                        String rawDate = obj.has("due_date") && !obj.get("due_date").isJsonNull() ? obj.get("due_date").getAsString() : "";
                        String displayDate = rawDate;
                        try {
                            if (!rawDate.isEmpty()) {
                                Date date = inputFormat.parse(rawDate);
                                displayDate = outputFormat.format(date);
                            }
                        } catch (Exception e) {
                            e.printStackTrace();
                        }
                        
                        int totalScore = obj.has("total_score") && !obj.get("total_score").isJsonNull() ? obj.get("total_score").getAsInt() : 100;
                        int score = 0;
                        
                        // Check for submissions
                        if (obj.has("submissions") && obj.get("submissions").isJsonArray()) {
                            JsonArray submissions = obj.getAsJsonArray("submissions");
                            if (submissions.size() > 0) {
                                JsonObject firstSubmission = submissions.get(0).getAsJsonObject();
                                if (firstSubmission.has("score") && !firstSubmission.get("score").isJsonNull()) {
                                    score = firstSubmission.get("score").getAsInt();
                                }
                            }
                        } else if (obj.has("attempts") && obj.get("attempts").isJsonArray()) {
                            JsonArray attempts = obj.getAsJsonArray("attempts");
                            if (attempts.size() > 0) {
                                JsonObject firstAttempt = attempts.get(0).getAsJsonObject();
                                if (firstAttempt.has("score") && !firstAttempt.get("score").isJsonNull()) {
                                    score = firstAttempt.get("score").getAsInt();
                                }
                            }
                        }
                        
                        String type = obj.has("assignment_type") && !obj.get("assignment_type").isJsonNull() ? obj.get("assignment_type").getAsString() : "assignment";
                        
                        assessmentList.add(new Assessment(id, title, displayDate, score, totalScore, type));
                    }
                    adapter.notifyDataSetChanged();
                    updateAverageScore();
                } else {
                    Toast.makeText(AssessmentDetailActivity.this, "Failed to load assignments", Toast.LENGTH_SHORT).show();
                }
            }

            @Override
            public void onFailure(Call<JsonObject> call, Throwable t) {
                Toast.makeText(AssessmentDetailActivity.this, "Network error: " + t.getMessage(), Toast.LENGTH_SHORT).show();
            }
        });
    }

    private void updateAverageScore() {
        if (assessmentList.isEmpty()) return;
        int totalEarned = 0;
        int totalPossible = 0;
        for (Assessment a : assessmentList) {
            totalEarned += a.getScore();
            totalPossible += a.getTotalScore();
        }
        int avg = totalPossible > 0 ? (totalEarned * 100) / totalPossible : 0;
        averageScoreText.setText(avg + "%");
    }

    @Override
    public boolean onOptionsItemSelected(@NonNull MenuItem item) {
        if (item.getItemId() == android.R.id.home) {
            onBackPressed();
            return true;
        }
        return super.onOptionsItemSelected(item);
    }
}
