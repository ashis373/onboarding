import { Button } from "@/components/ui/button";
import { useNavigate } from "react-router-dom";
import corhisLogo from "@/assets/corhis-logo.png";

const Index = () => {
  const navigate = useNavigate();

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-brand-light to-background p-4">
      <div className="text-center space-y-6 max-w-md">
        <div className="mx-auto mb-6 flex items-center justify-center">
          <div className="inline-flex items-center rounded-xl p-2 dark:bg-white/95 dark:shadow-sm">
            <img src={corhisLogo} alt="CorHis" className="h-14 w-auto object-contain" />
          </div>
        </div>
        <div>
          <h1 className="text-4xl font-bold mb-4 text-brand">CorHis</h1>
          <p className="text-xl text-muted-foreground mb-6">
            Employee Onboarding Platform
          </p>
          <p className="text-sm text-muted-foreground mb-8">
            Streamline your onboarding experience with interactive content and assessments
          </p>
        </div>
        <Button 
          onClick={() => navigate("/login")} 
          size="lg"
          className="w-full"
        >
          Get Started
        </Button>
      </div>
    </div>
  );
};

export default Index;
