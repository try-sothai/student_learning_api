package com.social7.studentlearnanalytics.adapter;

import android.view.LayoutInflater;
import android.view.View;
import android.view.ViewGroup;
import android.widget.ImageView;
import android.widget.TextView;
import androidx.annotation.NonNull;
import androidx.recyclerview.widget.RecyclerView;
import com.social7.studentlearnanalytics.R;
import com.social7.studentlearnanalytics.model.Assessment;
import java.util.List;

public class AssessmentAdapter extends RecyclerView.Adapter<AssessmentAdapter.AssessmentViewHolder> {

    private List<Assessment> assessmentList;

    public AssessmentAdapter(List<Assessment> assessmentList) {
        this.assessmentList = assessmentList;
    }

    @NonNull
    @Override
    public AssessmentViewHolder onCreateViewHolder(@NonNull ViewGroup parent, int viewType) {
        View view = LayoutInflater.from(parent.getContext()).inflate(R.layout.item_assessment, parent, false);
        return new AssessmentViewHolder(view);
    }

    @Override
    public void onBindViewHolder(@NonNull AssessmentViewHolder holder, int position) {
        Assessment assessment = assessmentList.get(position);
        holder.titleText.setText(assessment.getTitle());
        holder.dateText.setText(assessment.getDate());
        holder.scoreText.setText(assessment.getFormattedScore());

        // You can customize icon/color based on assessment type if needed
        if ("quiz".equalsIgnoreCase(assessment.getType())) {
            holder.indicatorView.setBackgroundColor(holder.itemView.getContext().getResources().getColor(R.color.warning_orange));
            holder.iconView.setImageResource(R.drawable.ic_analytics); // or specific quiz icon
        } else {
            holder.indicatorView.setBackgroundColor(holder.itemView.getContext().getResources().getColor(R.color.info_blue));
            holder.iconView.setImageResource(R.drawable.ic_assignment);
        }
    }

    @Override
    public int getItemCount() {
        return assessmentList != null ? assessmentList.size() : 0;
    }

    public static class AssessmentViewHolder extends RecyclerView.ViewHolder {
        View indicatorView;
        ImageView iconView;
        TextView titleText, dateText, scoreText;

        public AssessmentViewHolder(@NonNull View itemView) {
            super(itemView);
            indicatorView = itemView.findViewById(R.id.indicator_view);
            iconView = itemView.findViewById(R.id.assessment_icon);
            titleText = itemView.findViewById(R.id.assessment_title);
            dateText = itemView.findViewById(R.id.assessment_date);
            scoreText = itemView.findViewById(R.id.assessment_score);
        }
    }
}
